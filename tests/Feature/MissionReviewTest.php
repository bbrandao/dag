<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Services\AllowanceLedger;
use App\Services\EvidencePhoto;
use App\Services\MissionNotices;
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

    private bool $gatewayAvailable = true;
    private bool $supportsMenu = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09 13:00:00', 'UTC'));
        Storage::fake('local');
        config([
            'app.url' => 'https://dag.example.com',
            'dag.public_url' => 'https://dag.example',
            'dag.uazapi.url' => 'https://gateway.example',
            'dag.uazapi.token' => 'fake-token',
            'dag.uazapi.sender' => '+5571999990000',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/instance/status' => fn () => $this->gatewayAvailable ? Http::response([
                'status' => ['connected' => true, 'jid' => ['user' => '5571999990000']],
            ]) : Http::response([], 503),
            'https://gateway.example/send/text' => Http::response(['id' => 'test-message']),
            'https://gateway.example/send/media' => Http::response(['id' => 'test-image']),
            'https://gateway.example/send/menu' => fn () => $this->supportsMenu
                ? Http::response(['id' => 'test-menu'])
                : Http::response(['error' => 'Unsupported'], 422),
        ]);
    }

    public function test_photo_delivery_and_approval_credit_points_only_once(): void
    {
        [$guardian, $child, $mission] = $this->mission('required');
        $this->as($guardian)->patch('/app/avisos', ['enabled' => true])->assertRedirect('/app');
        $this->as($child)->patch('/app/avisos', ['enabled' => true])->assertRedirect('/app');
        $url = "/app/missoes/{$mission}";
        $review = "/guardian/revisoes/{$mission}";
        $this->as($guardian)->get('/guardian/revisoes')->assertInertia(fn (Assert $page) => $page
            ->where('date', '2026-11-09')->where('childName', $child->name)
            ->has('missions', 1)->where('missions.0.status', 'pending')->where('missions.0.points', 10));
        $this->get($review)->assertInertia(fn (Assert $page) => $page->where('mission.child_name', $child->name));
        $this->get('/guardian/revisoes?status=expired')->assertInertia(fn (Assert $page) => $page->has('missions', 0));
        $this->as($child);
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
        $this->assertDatabaseHas('mission_notices', ['recipient_id' => $guardian->id, 'kind' => 'submitted', 'status' => 'sent']);
        $this->assertDatabaseMissing('mission_notices', ['recipient_id' => $child->id]);
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->has('childMissions.missions', 0)->where('progress.0.periods.day.inReview', 1));
        $this->get('/app/missoes?status=in_review')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'in_review'));
        $caption = "*DAG: 📝*\n*Tarefa para REVISÃO!*\n\n> Arrumar a cama\n_"
            .$payload['comment']."_\n\n🕒 Prazo: 09/11/2026 18:00\n✅ Concluída em: 09/11/2026 10:00";
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.example/send/menu'
            && $request['number'] === '5571999991111' && $request['type'] === 'button' && $request['text'] === $caption
            && $request['choices'] === ["Revisar|url:https://dag.example{$review}"]
            && base64_decode($request['file'], true) === Storage::disk('local')->get($attempt->photo_path));
        Storage::disk('local')->assertExists($attempt->photo_path);
        $this->get("{$url}/fotos/{$attempt->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $otherFamily = Family::create(['name' => 'Outra família', 'timezone' => 'America/Bahia']);
        $other = $this->user($otherFamily, User::GUARDIAN, '+5571999993333');
        $this->as($other)->get("{$url}/fotos/{$attempt->id}")->assertNotFound();
        $this->get($review)->assertNotFound();
        $this->as($guardian)->get('/guardian/revisoes')->assertInertia(fn (Assert $page) => $page
            ->component('Reviews')->where('missions.0.id', $mission));
        $filters = "date=2026-11-09&child={$child->id}&status=in_review";
        $this->get("/guardian/revisoes?{$filters}")->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.id', $mission)->where('child', $child->id));
        $this->get('/guardian/revisoes?status=in_review&child='.$other->id)->assertInertia(fn (Assert $page) => $page->has('missions', 0));
        $this->get("{$review}?{$filters}")->assertInertia(fn (Assert $page) => $page->where('reviewQuery', $filters));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->where('progress.0.periods.day.inReview', 1)->where('progress.0.periods.day.possible', 10));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 1]);
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')->where('events.0.href', $review));
        $this->patch('/app/avisos/lidos', ['ids' => [$attempt->id]])->assertRedirect('/app/avisos');
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 0]);
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.unread', false));

        $decision = ['submission_id' => $attempt->id, 'decision' => 'approved', 'feedback' => 'Muito bem!'];
        $this->post("{$review}?{$filters}", $decision)->assertRedirect("{$review}?{$filters}")->assertSessionHasNoErrors();
        $this->get("/guardian/revisoes?{$filters}")->assertInertia(fn (Assert $page) => $page->has('missions', 0));
        $this->get('/guardian/revisoes?status=approved')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'approved'));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->where('progress.0.periods.day.approved', 1)->where('progress.0.periods.day.earned', 10)
            ->where('progress.0.periods.week.earned', 10)->where('progress.0.periods.month.earned', 10)
            ->where('progress.0.availablePoints', 10)->where('progress.0.awaitingConversion', true));
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page->has('events', 0));
        $this->post($review, $decision)->assertRedirect($review);
        $this->assertDatabaseCount('point_credits', 1);
        $this->assertDatabaseHas('point_credits', ['occurrence_id' => $mission, 'points' => 10]);
        $this->assertDatabaseCount('mission_notices', 2);
        $messages = Http::recorded(fn (Request $request) => $request->method() === 'POST')->values();
        $this->assertCount(2, $messages);
        $this->assertSame(["Revisar|url:https://dag.example{$review}"], $messages[0][0]['choices']);
        $this->assertSame(["Ver no DAG|url:https://dag.example{$url}"], $messages[1][0]['choices']);
        $this->assertSame("*DAG: ✅*\n*Missão APROVADA!*\n\n> Arrumar a cama\n_Muito bem!_", $messages[1][0]['text']);

        $this->as($child)->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('Mission')->where('mission.status', 'approved')->where('attempts.0.feedback', 'Muito bem!'));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page->where('earnedPoints', 10)
            ->has('childMissions.missions', 0)->where('progress.0.periods.day.approved', 1));
        $this->get('/app/missoes?status=approved')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'approved'));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 1]);

        $this->travel(91)->days();
        app(EvidencePhoto::class)->prune();
        Storage::disk('local')->assertMissing($attempt->photo_path);
        $this->assertDatabaseHas('mission_submissions', ['id' => $attempt->id, 'photo_path' => null]);
    }

    public function test_rejection_is_final_without_points_and_notifies_both_roles_at_their_step(): void
    {
        [$guardian, $child, $mission] = $this->mission('disabled');
        $this->as($guardian)->patch('/app/avisos', ['enabled' => true]);
        $this->as($child)->patch('/app/avisos', ['enabled' => true]);
        $this->supportsMenu = false;
        $url = "/app/missoes/{$mission}";
        $review = "/guardian/revisoes/{$mission}";
        $this->post("{$url}/entregas", ['request_id' => (string) Str::uuid(), 'comment' => 'Terminei.'])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.example/send/text'
            && $request['number'] === '5571999991111'
            && $request['text'] === "*DAG: 📝*\n*Tarefa para REVISÃO!*\n\n> Arrumar a cama\n_Terminei._\n\n🕒 Prazo: 09/11/2026 18:00\n✅ Concluída em: 09/11/2026 10:00\n\nRevisar: https://dag.example{$review}");

        $submission = DB::table('mission_submissions')->value('id');
        $this->as($guardian)->post($review, ['submission_id' => $submission, 'decision' => 'rejected', 'feedback' => 'Faltou a coberta.'])
            ->assertRedirect($review)->assertSessionHasNoErrors();
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page->has('events', 0));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 0]);
        $this->assertDatabaseCount('point_credits', 0);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.example/send/text'
            && $request['number'] === '5571999992222'
            && $request['text'] === "*DAG: 🚫*\n*Missão REPROVADA!*\n\n> Arrumar a cama\n_Faltou a coberta._\n\nVer no DAG: https://dag.example{$url}");
        $this->as($child)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('mission.status', 'rejected')->where('attempts.0.decision', 'rejected'));
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page->where('events.0.message', 'Missão reprovada'));
        $this->post("{$url}/entregas", ['request_id' => (string) Str::uuid()])->assertSessionHasErrors('mission');
        $this->assertDatabaseCount('mission_submissions', 1);
    }

    public function test_guardian_notice_retries_after_a_temporary_gateway_failure_without_a_review(): void
    {
        [$guardian, $child, $mission] = $this->mission('disabled');
        $this->as($guardian)->patch('/app/avisos', ['enabled' => true]);
        $this->gatewayAvailable = false;
        $this->as($child)->post("/app/missoes/{$mission}/entregas", ['request_id' => (string) Str::uuid()])
            ->assertRedirect("/app/missoes/{$mission}")->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mission_notices', ['kind' => 'submitted', 'status' => 'pending', 'attempts' => 1]);

        $this->gatewayAvailable = true;
        $this->travel(30)->seconds();
        app(MissionNotices::class)->deliver();
        $this->assertDatabaseHas('mission_notices', ['kind' => 'submitted', 'status' => 'sent', 'attempts' => 2]);
        $this->assertDatabaseHas('task_occurrences', ['id' => $mission, 'status' => 'in_review']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.example/send/menu'
            && $request['number'] === '5571999991111'
            && $request['text'] === "*DAG: 📝*\n*Tarefa para REVISÃO!*\n\n> Arrumar a cama\n\n🕒 Prazo: 09/11/2026 18:00\n✅ Concluída em: 09/11/2026 10:00");
    }

    public function test_guardian_can_filter_by_day_and_correct_a_review_until_allowance_closes(): void
    {
        [$guardian, $child, $mission] = $this->mission('disabled');
        $reviews = '/guardian/revisoes';
        $review = "{$reviews}/{$mission}";
        $this->as($guardian)->get("{$reviews}?date=2026-11-09")->assertInertia(fn (Assert $page) => $page
            ->where('date', '2026-11-09')->where('missions.0.status', 'pending'));
        $this->as($child)->patch('/app/avisos', ['enabled' => true]);
        $this->post("/app/missoes/{$mission}/entregas", ['request_id' => (string) Str::uuid()]);
        $submission = DB::table('mission_submissions')->value('id');
        $this->as($guardian)->post($review, ['submission_id' => $submission, 'decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $this->get("{$reviews}?date=2026-11-09")->assertInertia(fn (Assert $page) => $page->where('missions.0.status', 'approved'));
        $this->get("{$reviews}?date=2026-11-08")->assertInertia(fn (Assert $page) => $page->has('missions', 0));

        $this->as($child)->patch('/app/avisos/lidos', ['ids' => [$submission]]);
        $this->as($guardian)->post($review, ['submission_id' => $submission, 'decision' => 'rejected', 'review_version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('point_credits', 0);
        $this->as($child)->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 1]);
        $this->as($guardian)->post($review, ['submission_id' => $submission, 'decision' => 'adjustment', 'review_version' => 1, 'feedback' => 'Ajuste.'])
            ->assertSessionHasErrors('mission');
        $this->post($review, ['submission_id' => $submission, 'decision' => 'adjustment', 'review_version' => 2, 'feedback' => 'Ajuste.'])
            ->assertSessionHasNoErrors();
        $this->post($review, ['submission_id' => $submission, 'decision' => 'approved', 'review_version' => 3])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('point_credits', 1);
        $this->assertDatabaseCount('mission_review_changes', 3);
        $this->assertDatabaseHas('mission_notices', ['submission_id' => $submission, 'kind' => 'approved', 'review_version' => 4, 'status' => 'sent']);

        $ledger = app(AllowanceLedger::class);
        $ledger->setRate($guardian, 10);
        $credits = app(AllowanceRepository::class)->availableCredits($child);
        $this->assertNotNull($ledger->manual($child, $guardian, (string) Str::uuid(), $ledger->fingerprint($credits)));
        $this->post($review, ['submission_id' => $submission, 'decision' => 'rejected', 'review_version' => 4])
            ->assertSessionHasErrors('mission');
        $this->assertDatabaseHas('mission_submissions', ['id' => $submission, 'decision' => 'approved', 'review_version' => 4]);
        $this->assertDatabaseCount('payout_items', 1);
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
        $this->get('/guardian/revisoes?status=adjustment')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'adjustment'));
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page->has('events', 0));
        $this->assertDatabaseCount('point_credits', 0);

        $this->travelTo(Carbon::parse('2026-11-09 21:00:00', 'UTC'));
        $this->get('/guardian/revisoes?status=adjustment')->assertInertia(fn (Assert $page) => $page->has('missions', 0));
        $this->get('/guardian/revisoes?status=expired')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'expired'));
        $payload = ['request_id' => (string) Str::uuid()];
        $this->as($child)->post("{$url}/entregas", $payload)->assertSessionHasErrors('mission');
        $this->as($guardian)->post("{$review}/reabrir", ['new_due_at' => '2026-11-09T20:00', 'reason' => 'Prazo estendido.'])
            ->assertRedirect($review)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mission_reopenings', 1);
        $this->as($child)->get('/app')->assertInertia(fn (Assert $page) => $page
            ->has('childMissions.missions', 1)->where('childMissions.missions.0.id', $mission)
            ->where('childMissions.missions.0.status', 'adjustment')->where('childMissions.missions.0.due_time', '20:00'));
        $this->get('/app/missoes?status=adjustment')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.id', $mission));
        $this->post("{$url}/entregas", $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mission_submissions', 2);
        $this->assertDatabaseHas('task_occurrences', ['id' => $mission, 'status' => 'in_review']);
        $newAttempt = DB::table('mission_submissions')->where('request_id', $payload['request_id'])->value('id');
        $this->as($guardian)->get('/app/avisos')->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)->where('events.0.id', $newAttempt)->where('events.0.unread', true));
        Http::assertNothingSent();
    }

    public function test_notice_summary_caps_the_preview_and_the_inbox_paginates_unread_notices(): void
    {
        [$guardian, $child, $mission] = $this->mission('disabled');
        $missions = [$mission];
        for ($index = 2; $index <= 17; $index++) {
            $title = "Missão {$index}";
            $this->post('/guardian/tasks', [
                'title' => $title, 'criteria' => 'Concluir atividade.', 'points' => 1,
                'frequency' => 'daily', 'starts_on' => '2026-11-09', 'weekday_time' => '18:00',
                'weekend_time' => '12:00', 'daily_days' => [1], 'child_ids' => [$child->id], 'photo_mode' => 'disabled',
            ])->assertSessionHasNoErrors();
            $missions[] = DB::table('task_occurrences')->where('title', $title)->value('id');
        }

        foreach ($missions as $mission) {
            $this->travel(1)->minutes();
            $this->as($child)->post("/app/missoes/{$mission}/entregas", ['request_id' => (string) Str::uuid()])
                ->assertSessionHasNoErrors();
        }
        $this->as($guardian)->get('/app/avisos')->assertInertia(fn (Assert $page) => $page
            ->has('events', 5)->where('events.0.title', 'Missão 17')->where('events.4.title', 'Missão 13'));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 17]);
        $this->getJson('/app/avisos/resumo')->assertOk()->assertJsonPath('unread', 17)->assertJsonCount(5, 'events');

        foreach ($missions as $mission) {
            $this->travel(1)->minutes();
            $submission = DB::table('mission_submissions')->where('occurrence_id', $mission)->value('id');
            $this->post("/guardian/revisoes/{$mission}", ['submission_id' => $submission, 'decision' => 'approved'])
                ->assertSessionHasNoErrors();
        }
        $this->as($child)->patchJson('/app/avisos/lidos', ['ids' => [$submission]])->assertNoContent();
        $this->get('/app/avisos')->assertInertia(fn (Assert $page) => $page->has('events', 5)
            ->where('events.0.id', $submission)->where('events.0.unread', false)->where('events.4.title', 'Missão 13'));
        $this->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 16]);
        $this->getJson('/app/avisos/resumo')->assertOk()->assertJsonPath('unread', 16)->assertJsonCount(5, 'events')
            ->assertJsonPath('events.0.title', 'Missão 16')->assertJsonPath('events.4.title', 'Missão 12');
        $this->get('/app/avisos?filter=unread')->assertInertia(fn (Assert $page) => $page
            ->where('unreadOnly', true)->where('noticeSummary.unread', 16)->has('events', 15)
            ->where('events.14.title', 'Missão 2')->where('pagination.previous', null)
            ->where('pagination.next', function (string $next) {
                $this->get($next)->assertInertia(fn (Assert $page) => $page
                    ->has('events', 1)->where('events.0.title', 'Arrumar a cama')->where('pagination.next', null));

                return true;
            }));
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
