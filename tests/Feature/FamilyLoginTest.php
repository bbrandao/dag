<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FamilyLoginTest extends TestCase
{
    use RefreshDatabase;

    private const GUARDIAN_PHONE = '+5571999991111';

    private const CHILD_PHONE = '+5571999992222';

    private array $gatewayStatus = [
        'status' => ['connected' => true, 'jid' => ['user' => '5571999990000']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'dag.phone_key' => str_repeat('p', 32),
            'dag.otp_key' => str_repeat('o', 32),
            'dag.uazapi.url' => 'https://gateway.example',
            'dag.uazapi.token' => 'fake-token',
            'dag.uazapi.sender' => '+5571999990000',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/instance/status' => fn () => Http::response($this->gatewayStatus),
            'https://gateway.example/send/text' => Http::response(['id' => 'test-message']),
        ]);
    }

    public function test_guardian_registers_child_and_child_signs_in_with_own_code(): void
    {
        $guardian = $this->guardian();
        $this->post('/auth/otp', ['phone' => self::GUARDIAN_PHONE, 'permission' => true])->assertRedirect('/login');
        $code = $this->sentCode(self::GUARDIAN_PHONE);
        $this->assertDatabaseMissing('otp_challenges', ['digest' => $code]);
        $this->post('/auth/otp/verify', ['code' => $code])->assertRedirect('/app');
        $this->assertAuthenticatedAs($guardian);

        $this->post('/guardian/children', $this->childData())->assertRedirect('/app')->assertSessionHasNoErrors();
        $child = User::where('phone_hash', Phone::fingerprint(self::CHILD_PHONE))->firstOrFail();
        $this->assertDatabaseHas('guardian_child_links', ['guardian_id' => $guardian->id, 'child_id' => $child->id]);
        $this->assertNotSame(self::CHILD_PHONE, $child->getRawOriginal('phone'));
        $this->assertDatabaseMissing('children', ['birth_date' => '2013-01-01']);
        $this->get('/app')->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('role', 'guardian')->where('children.0.id', $child->id));

        $this->post('/logout')->assertRedirect('/login');
        $this->post('/auth/otp', ['phone' => self::CHILD_PHONE, 'permission' => true])->assertRedirect('/login');
        $this->post('/auth/otp/verify', ['code' => $this->sentCode(self::CHILD_PHONE)])->assertRedirect('/app');
        $this->assertAuthenticatedAs($child);
        $this->get('/app')->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('role', 'child')->has('children', 0));
        $this->post('/guardian/children', $this->childData())->assertForbidden();
    }

    public function test_unknown_phone_has_same_redirect_and_sends_nothing(): void
    {
        $this->post('/auth/otp', ['phone' => self::CHILD_PHONE, 'permission' => true])->assertRedirect('/login');
        Http::assertNothingSent();
        $this->post('/auth/otp/verify', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_can_be_used_only_once_and_session_revocation_takes_effect(): void
    {
        $guardian = $this->guardian();
        $this->post('/auth/otp', ['phone' => self::GUARDIAN_PHONE, 'permission' => true]);
        $id = session('otp_challenge');
        $code = $this->sentCode(self::GUARDIAN_PHONE);
        $this->post('/auth/otp/verify', ['code' => $code])->assertRedirect('/app');

        $guardian->forceFill(['session_version' => 2])->save();
        $this->app['auth']->forgetGuards();
        $this->get('/app')->assertRedirect('/login');
        $this->assertGuest();
        $this->withSession(['otp_challenge' => $id])->post('/auth/otp/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_code_is_not_sent_if_paired_number_is_different(): void
    {
        $this->guardian();
        config(['dag.uazapi.sender' => '+5571999993333']);

        $this->post('/auth/otp', ['phone' => self::GUARDIAN_PHONE, 'permission' => true])->assertRedirect('/login');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertGuest();
    }

    public function test_code_is_sent_when_connected_instance_identifies_sender_as_owner(): void
    {
        $this->guardian();
        $this->gatewayStatus = [
            'instance' => ['status' => 'connected', 'owner' => '5571999990000'],
            'status' => ['jid' => '12345678901234@lid'],
        ];

        $this->post('/auth/otp', ['phone' => self::GUARDIAN_PHONE, 'permission' => true])->assertRedirect('/login');

        $this->sentCode(self::GUARDIAN_PHONE);
    }

    public function test_explicit_disconnect_blocks_sending_even_when_instance_reports_connected(): void
    {
        $this->guardian();
        $this->gatewayStatus['instance'] = ['status' => 'connected', 'owner' => '5571999990000'];
        $this->gatewayStatus['status']['connected'] = false;

        $this->post('/auth/otp', ['phone' => self::GUARDIAN_PHONE, 'permission' => true])->assertRedirect('/login');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_resend_replaces_previous_challenge_and_code_expires(): void
    {
        $this->guardian();
        $payload = ['phone' => self::GUARDIAN_PHONE, 'permission' => true];
        $this->post('/auth/otp', $payload)->assertRedirect('/login');
        $previous = session('otp_challenge');
        $this->post('/auth/otp', $payload)->assertTooManyRequests();

        $this->travel(61)->seconds();
        $this->post('/auth/otp', $payload)->assertRedirect('/login');
        $this->assertNotSame($previous, session('otp_challenge'));
        $this->assertNotNull(DB::table('otp_challenges')->where('id', $previous)->value('consumed_at'));
        $code = $this->sentCode(self::GUARDIAN_PHONE);
        $this->travel(5)->minutes();
        $this->post('/auth/otp/verify', ['code' => $code])->assertSessionHasErrors('code');
    }

    private function guardian(): User
    {
        return User::create([
            'family_id' => Family::create(['name' => 'Família Teste', 'timezone' => 'America/Bahia'])->id,
            'name' => 'Responsável Teste',
            'role' => User::GUARDIAN,
            'relationship' => 'responsável',
            'phone' => self::GUARDIAN_PHONE,
            'phone_hash' => Phone::fingerprint(self::GUARDIAN_PHONE),
        ])->refresh();
    }

    private function childData(): array
    {
        return ['name' => 'Perfil Teste', 'birth_date' => '2013-01-01', 'phone' => self::CHILD_PHONE, 'authorized' => true];
    }

    private function sentCode(string $phone): string
    {
        $record = Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && $request['number'] === substr($phone, 1))->last();
        $this->assertNotNull($record);
        $request = $record[0];
        $this->assertTrue($request->hasHeader('token', 'fake-token'));
        $this->assertSame(1, preg_match('/\b[0-9]{6}\b/', $request['text'], $match));

        return $match[0];
    }
}
