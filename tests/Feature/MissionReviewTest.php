<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use App\Services\EvidencePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MissionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09 13:00:00', 'UTC'));
        Storage::fake('local');
        config([
            'app.url' => 'https://dag.example',
            'dag.uazapi.url' => 'https://gateway.example',
            'dag.uazapi.token' => 'fake-token',
            'dag.uazapi.sender' => '+5571999990000',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/instance/status' => Http::response([
                'status' => ['connected' => true, 'jid' => ['user' => '5571999990000']],
            ]),
            'https://gateway.example/send/text' => Http::response(['id' => 'test-message']),
        ]);
    }

    public function test_photo_delivery_and_approval_credit_points_only_once(): void
    {
        [$guardian, $child, $mission] = $this->mission('required');
        $this->as($guardian)->patch('/app/avisos', ['enabled' => true])->assertRedirect('/app');
        $this->as($child)->patch('/app/avisos', ['enabled' => true])->assertRedirect('/app');
        $url = "/app/missoes/{$mission}";
        $review = "/guardian/revisoes/{$mission}";
        $payload = ['request_id' => (string) Str::uuid(), 'comment' => str_repeat('a', 255)];

        $this->post("{$url}/entregas", [...$payload, 'comment' => str_repeat('a', 256)])->assertSessionHasErrors('comment');
        $this->post("{$url}/entregas", $payload)->assertSessionHasErrors('photo');
        $this->post("{$url}/entregas", [...$payload, 'photo' => UploadedFile::fake()->create('imagem.png', 1)])
            ->assertSessionHasErrors('photo');
        $this->post("{$url}/entregas", [...$payload, 'photo' => UploadedFile::fake()->image('imagem.png')])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->post("{$url}/entregas", $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $attempt = DB::table('mission_submissions')->first();
        $this->assertDatabaseCount('mission_submissions', 1);
        Storage::disk('local')->assertExists($attempt->photo_path);
        $this->get("{$url}/fotos/{$attempt->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $otherFamily = Family::create(['name' => 'Outra família', 'timezone' => 'America/Bahia']);
        $other = $this->user($otherFamily, User::GUARDIAN, '+5571999993333');
        $this->as($other)->get("{$url}/fotos/{$attempt->id}")->assertNotFound();
        $this->get($review)->assertNotFound();
        $this->as($guardian)->get('/guardian/revisoes')->assertInertia(fn (Assert $page) => $page
            ->component('Reviews')->where('missions.0.id', $mission));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 1]);
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')->where('events.0.href', $review));
        $this->patch('/app/avisos/lidos', ['ids' => [$attempt->id]])->assertRedirect('/app/avisos');
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 0]);

        $decision = ['submission_id' => $attempt->id, 'decision' => 'approved', 'feedback' => 'Muito bem!'];
        $this->post($review, $decision)->assertRedirect($review)->assertSessionHasNoErrors();
        $this->post($review, $decision)->assertRedirect($review);
        $this->assertDatabaseCount('point_credits', 1);
        $this->assertDatabaseHas('point_credits', ['occurrence_id' => $mission, 'points' => 10]);
        $this->assertDatabaseCount('mission_notices', 2);
        $messages = Http::recorded(fn (Request $request) => $request->method() === 'POST')->values();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString("https://dag.example{$review}", $messages[0][0]['text']);
        $this->assertStringContainsString("https://dag.example{$url}", $messages[1][0]['text']);
        $this->assertStringNotContainsString('Muito bem!', $messages[1][0]['text']);

        $this->as($child)->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('Mission')->where('mission.status', 'approved')->where('attempts.0.feedback', 'Muito bem!'));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page->where('earnedPoints', 10));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 1]);

        $this->travel(91)->days();
        app(EvidencePhoto::class)->prune();
        Storage::disk('local')->assertMissing($attempt->photo_path);
        $this->assertDatabaseHas('mission_submissions', ['id' => $attempt->id, 'photo_path' => null]);
    }

    public function test_adjustment_requires_feedback_and_expired_mission_requires_reopening(): void
    {
        [$guardian, $child, $mission] = $this->mission('disabled');
        $url = "/app/missoes/{$mission}";
        $review = "/guardian/revisoes/{$mission}";
        $this->as($child)->post("{$url}/entregas", ['request_id' => (string) Str::uuid()])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $decision = ['submission_id' => DB::table('mission_submissions')->value('id'), 'decision' => 'adjustment'];
        $this->as($guardian)->post($review, $decision)->assertSessionHasErrors('feedback');
        $this->post($review, [...$decision, 'feedback' => 'Dobre a coberta.'])->assertRedirect($review)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('point_credits', 0);

        $this->travelTo(Carbon::parse('2026-11-09 21:00:00', 'UTC'));
        $payload = ['request_id' => (string) Str::uuid()];
        $this->as($child)->post("{$url}/entregas", $payload)->assertSessionHasErrors('mission');
        $this->as($guardian)->post("{$review}/reabrir", ['new_due_at' => '2026-11-09T20:00', 'reason' => 'Prazo estendido.'])
            ->assertRedirect($review)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mission_reopenings', 1);
        $this->as($child)->post("{$url}/entregas", $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mission_submissions', 2);
        $this->assertDatabaseHas('task_occurrences', ['id' => $mission, 'status' => 'in_review']);
        Http::assertNothingSent();
    }

    private function mission(string $photoMode): array
    {
        $family = Family::create(['name' => 'Família Teste', 'timezone' => 'America/Bahia']);
        $guardian = $this->user($family, User::GUARDIAN, '+5571999991111');
        $child = $this->user($family, User::CHILD, '+5571999992222');
        DB::table('children')->insert([
            'user_id' => $child->id, 'family_id' => $family->id, 'birth_date' => encrypt('2013-01-01'),
        ]);
        DB::table('guardian_child_links')->insert([
            'family_id' => $family->id, 'guardian_id' => $guardian->id, 'child_id' => $child->id, 'authorized_at' => now(),
        ]);
        $this->as($guardian)->post('/guardian/tasks', [
            'title' => 'Arrumar a cama', 'criteria' => 'Coberta dobrada.', 'points' => 10,
            'frequency' => 'daily', 'starts_on' => '2026-11-09', 'weekday_time' => '18:00',
            'weekend_time' => '12:00', 'daily_days' => [1], 'child_ids' => [$child->id], 'photo_mode' => $photoMode,
        ])->assertSessionHasNoErrors();

        return [$guardian, $child, DB::table('task_occurrences')->value('id')];
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['account_version' => $user->session_version]);
    }

    private function user(Family $family, string $role, string $phone): User
    {
        return User::create([
            'family_id' => $family->id, 'name' => 'Perfil Teste', 'role' => $role,
            'phone' => $phone, 'phone_hash' => hash('sha256', $phone),
        ])->refresh();
    }
}
