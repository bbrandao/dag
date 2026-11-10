<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use App\Services\MissionPush;
use App\Services\PushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

final class WebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09 20:44:00', 'UTC'));
        config(['dag.web_push' => [
            'subject' => 'mailto:dag@example.test', 'publicKey' => 'test-public', 'privateKey' => 'test-private',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_reminder_is_sent_once_per_deadline_to_the_childs_device(): void
    {
        [$guardian, $child, $mission] = $this->mission();
        $this->enable($child, 'child');
        $this->enable($guardian, 'guardian');

        $this->partialMock(PushTransport::class)->shouldReceive('send')->once()
            ->withArgs(fn (array $subscription, array $payload, int $ttl) =>
                $subscription['endpoint'] === 'https://fcm.googleapis.com/fcm/send/child'
                && $payload['path'] === "/app/missoes/{$mission}" && $ttl === 900)
            ->andReturn('sent');
        $push = app(MissionPush::class);
        $push->tick();
        $this->assertDatabaseCount('push_deliveries', 0);

        $this->travel(1)->minute();
        $push->tick();
        $push->tick();
        $this->assertDatabaseCount('push_deliveries', 1);
        $this->assertDatabaseHas('push_deliveries', ['recipient_id' => $child->id, 'status' => 'sent', 'attempts' => 1]);
        $this->travel(16)->minutes();
        $push->tick();
        $this->assertDatabaseCount('push_deliveries', 1);
    }

    public function test_submission_notifies_linked_guardian_and_preserves_whatsapp_and_inbox(): void
    {
        [$guardian, $child, $mission] = $this->mission();
        $other = $this->user($guardian->family, User::GUARDIAN, '+5571000000103');
        $this->enable($guardian, 'guardian');
        $this->enable($other, 'unlinked');
        $this->enable($child, 'child');
        $this->as($guardian)->patch('/app/avisos', ['enabled' => true])->assertRedirect('/app');
        config([
            'dag.uazapi.url' => 'https://gateway.example',
            'dag.uazapi.token' => 'test-token',
            'dag.uazapi.sender' => '+5571000000101',
        ]);
        Http::fake([
            'https://gateway.example/instance/status' => Http::response(['status' => ['connected' => true, 'jid' => '5571000000101@s.whatsapp.net']]),
            'https://gateway.example/send/menu' => Http::response(['id' => 'test-message']),
        ]);
        $this->partialMock(PushTransport::class)->shouldReceive('send')->once()
            ->withArgs(fn (array $subscription, array $payload, int $ttl) =>
                $subscription['endpoint'] === 'https://fcm.googleapis.com/fcm/send/guardian'
                && $payload['path'] === "/guardian/revisoes/{$mission}" && $ttl > 0)
            ->andReturn('sent');
        $payload = ['request_id' => (string) Str::uuid()];
        $this->as($child)->post("/app/missoes/{$mission}/entregas", $payload)->assertSessionHasNoErrors();
        $this->post("/app/missoes/{$mission}/entregas", $payload)->assertSessionHasNoErrors();
        $this->travel(1)->minute();
        app(MissionPush::class)->tick();
        $this->assertDatabaseCount('push_deliveries', 1);
        $this->assertDatabaseHas('push_deliveries', ['recipient_id' => $guardian->id, 'status' => 'sent']);
        $this->assertDatabaseHas('mission_notices', ['recipient_id' => $guardian->id, 'kind' => 'submitted', 'status' => 'sent']);
        $this->as($guardian)->getJson('/app/avisos/contagem')->assertExactJson(['unread' => 1]);
    }

    public function test_subscription_is_device_scoped_and_rejects_private_or_unknown_endpoints(): void
    {
        [$guardian, $child] = $this->mission();
        $body = $this->enable($child, 'device');
        $this->as($guardian)->postJson('/app/push/status', $body)->assertExactJson(['enabled' => false]);
        $this->deleteJson('/app/push/assinatura', $body)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        foreach (['https://127.0.0.1/internal', 'https://fcm.googleapis.com.example.test/send'] as $endpoint) {
            $this->postJson('/app/push/assinatura', [...$body, 'endpoint' => $endpoint])->assertJsonValidationErrors('endpoint');
        }
        $this->as($child)->postJson('/app/push/status', $body)->assertExactJson(['enabled' => true]);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_transport_encrypts_push_requests_and_identifies_expired_devices(): void
    {
        try {
            $vapid = VAPID::createVapidKeys();
        } catch (\RuntimeException $error) {
            if (PHP_OS_FAMILY === 'Windows' && getenv('OPENSSL_CONF') === false) {
                $this->markTestSkipped('Defina OPENSSL_CONF com o arquivo de configuração do PHP.');
            }

            throw $error;
        }
        config(['dag.web_push' => ['subject' => 'mailto:dag@example.test', ...$vapid]]);
        $requests = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(410)]));
        $stack->push(Middleware::history($requests));
        $transport = new PushTransport(new Client(['handler' => $stack]));
        $subscription = [...$this->subscription('browser'), 'contentEncoding' => 'aes128gcm'];
        $subscription['keys']['p256dh'] = VAPID::createVapidKeys()['publicKey'];
        $payload = ['title' => 'DAG', 'body' => 'Entrega', 'path' => '/app'];

        $this->assertSame('sent', $transport->send($subscription, $payload, 900));
        $this->assertSame('expired', $transport->send($subscription, $payload, 900));
        $request = $requests[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('900', $request->getHeaderLine('TTL'));
        $this->assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('Entrega', (string) $request->getBody());
    }

    private function enable(User $user, string $device): array
    {
        $body = $this->subscription($device);
        $this->as($user)->postJson('/app/push/assinatura', $body)->assertExactJson(['enabled' => true]);

        return $body;
    }

    private function subscription(string $device): array
    {
        return [
            'endpoint' => "https://fcm.googleapis.com/fcm/send/{$device}",
            'keys' => [
                'p256dh' => 'B'.str_repeat('A', 86),
                'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            ],
        ];
    }

    private function mission(): array
    {
        $family = Family::create(['name' => 'Família Teste', 'timezone' => 'America/Bahia']);
        $guardian = $this->user($family, User::GUARDIAN, '+5571000000101');
        $child = $this->user($family, User::CHILD, '+5571000000102');
        DB::table('children')->insert([
            'user_id' => $child->id, 'family_id' => $family->id, 'birth_date' => encrypt('2013-01-01'),
        ]);
        DB::table('guardian_child_links')->insert([
            'family_id' => $family->id, 'guardian_id' => $guardian->id, 'child_id' => $child->id, 'authorized_at' => now(),
        ]);
        $this->as($guardian)->post('/guardian/tasks', [
            'title' => 'Arrumar a cama', 'criteria' => 'Coberta dobrada.', 'points' => 10,
            'frequency' => 'daily', 'starts_on' => '2026-11-09', 'weekday_time' => '18:00',
            'weekend_time' => '12:00', 'daily_days' => [1], 'child_ids' => [$child->id], 'photo_mode' => 'disabled',
        ])->assertSessionHasNoErrors();

        return [$guardian, $child, DB::table('task_occurrences')->value('id')];
    }

    private function user(Family $family, string $role, string $phone): User
    {
        return User::create([
            'family_id' => $family->id, 'name' => 'Perfil Teste', 'role' => $role,
            'phone' => $phone, 'phone_hash' => hash('sha256', $phone),
        ])->refresh();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['account_version' => $user->session_version]);
    }
}
