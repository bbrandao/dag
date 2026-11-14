<?php

namespace Tests\Integration;

use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Repositories\FamilyRepository;
use App\Repositories\InboxRepository;
use App\Repositories\MissionNoticeRepository;
use App\Repositories\MissionRepository;
use App\Repositories\OtpRepository;
use App\Repositories\TaskRepository;
use App\Services\AllowanceLedger;
use App\Services\AllowanceOverview;
use App\Services\ChildProfileService;
use App\Services\DashboardService;
use App\Services\FamilyProvisioning;
use App\Services\InboxService;
use App\Services\MissionNotices;
use App\Services\MissionOverview;
use App\Services\MissionService;
use App\Services\TaskService;
use App\Support\Phone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DagComponentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 13:00:00', 'UTC'));
        config(['dag.phone_key' => str_repeat('p', 32)]);
        Http::preventStrayRequests();
    }

    public function test_provisioning_rolls_back_duplicate_and_profiles_are_scoped_to_the_guardian(): void
    {
        [$guardian, $child] = $this->family();
        [$other] = $this->family(2);
        $families = app(FamilyRepository::class);
        $profiles = app(ChildProfileService::class);

        $this->assertSame([$child->id], $families->activeChildIds($guardian)->all());
        $this->assertSame('2013-01-01', $profiles->editData($guardian, $child->id)['child']['birth_date']);
        try {
            app(FamilyProvisioning::class)->provision($this->provisionData($guardian->phone));
            $this->fail('A operação deveria rejeitar o WhatsApp duplicado.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('families', 2);
            $this->assertDatabaseCount('users', 4);
        }

        $this->expectException(ModelNotFoundException::class);
        $profiles->editData($other, $child->id);
    }

    public function test_phone_change_invalidates_otp_and_remember_token_without_replacing_child(): void
    {
        [$guardian, $child] = $this->family();
        $challenges = app(OtpRepository::class);
        $id = (string) Str::uuid();
        $challenges->insert([
            'id' => $id, 'user_id' => $child->id, 'digest' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5),
        ]);
        $challenges->failedAttempt($id);
        $this->assertSame(1, $challenges->find($id)->attempts);
        $this->assertTrue($challenges->hasLiveChallengeSince($child->id, now()));
        $child->forceFill(['remember_token' => 'example', 'whatsapp_notices' => true])->save();

        app(ChildProfileService::class)->update($guardian, $child->id, [
            'name' => 'Atualizado', 'birth_date' => '2013-01-02', 'phone' => '+5571000003333',
        ]);
        $this->assertFalse($challenges->hasLiveChallengeSince($child->id, now()));
        $this->assertNotNull($challenges->find($id)->consumed_at);
        $this->assertSame(2, $child->refresh()->session_version);
        $this->assertNull($child->remember_token);
        $this->assertSame($child->id, app(FamilyRepository::class)->linkedChild($guardian, $child->id)->id);
    }

    public function test_task_repository_keeps_the_occurrence_snapshot_after_an_edit_and_archive(): void
    {
        [$guardian, $child] = $this->family();
        $service = app(TaskService::class);
        $repository = app(TaskRepository::class);
        $payload = $this->taskData($child);
        $service->save($guardian, $payload);
        $task = $repository->forGuardian($guardian, collect([$child->id]))->sole();
        $service->save($guardian, [...$payload, 'title' => 'Novo nome'], $task->id);

        $this->assertSame('Tarefa teste', $repository->childMissions($child, 'daily', now(), now()->startOfDay(), now()->addDay()->startOfDay())->sole()->title);
        $this->assertSame('Novo nome', $service->dashboard($guardian)['tasks']->sole()['title']);
        $service->delete($guardian, $task->id);
        $this->assertTrue($repository->forGuardian($guardian, collect([$child->id]))->isEmpty());
        $this->assertCount(1, $service->missions($child, ['frequency' => 'daily'])['missions']);
    }

    public function test_mission_services_credit_once_and_inbox_reads_are_per_recipient(): void
    {
        [$guardian, $child] = $this->family();
        app(TaskService::class)->save($guardian, $this->taskData($child));
        $mission = app(TaskRepository::class)->childMissions($child, null, now(), now()->startOfDay(), now()->addDay()->startOfDay())->sole();
        $service = app(MissionService::class);
        $repository = app(MissionRepository::class);
        $overview = app(MissionOverview::class);
        $request = ['request_id' => (string) Str::uuid(), 'comment' => 'Pronta.'];
        $service->submit($child, $mission->id, $request);
        $service->submit($child, $mission->id, $request);
        $submission = $repository->attempts($mission->id)->sole();
        $this->assertSame($mission->id, $overview->reviews($guardian)['missions']->sole()['id']);

        $decision = ['submission_id' => $submission->id, 'decision' => 'approved'];
        $service->review($guardian, $mission->id, $decision);
        $service->review($guardian, $mission->id, $decision);
        $this->assertSame('approved', $overview->details($child, $mission->id, User::CHILD)['mission']['status']);
        $this->assertSame(10, app(AllowanceRepository::class)->earnedPoints($child));
        $this->assertDatabaseCount('point_credits', 1);

        $inbox = app(InboxService::class);
        $this->assertSame('Missão aprovada', $inbox->events($child)->sole()['message']);
        $inbox->markRead($child, [$submission->id]);
        $this->assertSame(0, app(InboxRepository::class)->unread($child));
        $this->assertSame(0, app(InboxRepository::class)->unread($guardian));
    }

    public function test_allowance_services_value_credit_close_and_keep_dashboard_totals_consistent(): void
    {
        [$guardian, $child] = $this->family();
        app(TaskService::class)->save($guardian, $this->taskData($child));
        $mission = app(TaskRepository::class)->childMissions($child, null, now(), now()->startOfDay(), now()->addDay()->startOfDay())->sole();
        $ledger = app(AllowanceLedger::class);
        $occurrence = app(MissionRepository::class)->find($child, $mission->id);
        $ledger->credit($occurrence, now()->toImmutable());
        $overview = app(AllowanceOverview::class);
        $this->assertTrue($overview->data($child)['unpriced']);

        $ledger->setRate($guardian, 10);
        $preview = $overview->data($guardian, $child->id)['preview'];
        $this->assertSame(100, $preview['amount_cents']);
        $payout = $ledger->manual($child, $guardian, $preview['request_id'], $preview['fingerprint']);
        $this->assertNotNull($payout);
        $this->assertSame($payout, $ledger->manual($child, $guardian, $preview['request_id'], $preview['fingerprint']));
        $dashboard = app(DashboardService::class);
        $this->assertSame(0, $dashboard->data($guardian)['progress']->sole()['availablePoints']);
        $this->assertSame(100, $overview->data($child)['pendingCents']);

        $ledger->markPaid($child, $guardian, $payout);
        $this->assertSame(100, $dashboard->data($guardian)['progress']->sole()['paidThisMonth']);
        $this->assertSame(100, $overview->data($child)['months']->first()['paid_cents']);
    }

    public function test_notice_repository_allows_only_one_claim_and_flags_stale_deliveries(): void
    {
        [$guardian, $child] = $this->family();
        app(FamilyRepository::class)->setNoticePreference($guardian, true);
        app(TaskService::class)->save($guardian, $this->taskData($child));
        $mission = app(TaskRepository::class)->childMissions($child, null, now(), now()->startOfDay(), now()->addDay()->startOfDay())->sole();
        app(MissionService::class)->submit($child, $mission->id, ['request_id' => (string) Str::uuid()]);
        $repository = app(MissionNoticeRepository::class);
        $notice = $repository->ready(now(), null)->sole();
        $this->assertSame($guardian->id, $notice->recipient_id);
        $this->assertTrue($repository->claim($notice));
        $this->assertFalse($repository->claim($notice));

        $this->travel(3)->minutes();
        app(MissionNotices::class)->deliver();
        $this->assertTrue($repository->ready(now(), null)->isEmpty());
        $this->assertDatabaseHas('mission_notices', ['id' => $notice->id, 'status' => 'unknown']);
        Http::assertNothingSent();
    }

    private function family(int $number = 1): array
    {
        $phone = '+557100000'.$number.'001';
        app(FamilyProvisioning::class)->provision($this->provisionData($phone));
        $guardian = User::where('phone_hash', Phone::fingerprint($phone))->firstOrFail();
        $childPhone = '+557100000'.$number.'002';
        app(ChildProfileService::class)->register($guardian, [
            'name' => 'Perfil Teste', 'birth_date' => '2013-01-01', 'phone' => $childPhone,
        ]);

        return [$guardian, User::where('phone_hash', Phone::fingerprint($childPhone))->firstOrFail()];
    }

    private function provisionData(string $phone): array
    {
        return ['family' => 'Família Teste', 'timezone' => 'America/Bahia', 'name' => 'Responsável', 'relationship' => 'responsável', 'phone' => $phone];
    }

    private function taskData(User $child): array
    {
        return [
            'title' => 'Tarefa teste', 'criteria' => 'Pronta.', 'points' => 10, 'frequency' => 'daily',
            'starts_on' => '2026-11-09', 'weekday_time' => '18:00', 'weekend_time' => '12:00',
            'daily_days' => [1], 'child_ids' => [$child->id], 'photo_mode' => 'disabled',
        ];
    }
}
