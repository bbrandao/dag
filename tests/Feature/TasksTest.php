<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09 13:00:00', 'UTC'));
    }

    public function test_daily_mission_uses_local_weekday_and_weekend_times_and_expires_at_the_deadline(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian)->post('/guardian/tasks', $this->task($child))->assertRedirect('/guardian/tasks');

        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->component('Missions')->has('missions', 1)
            ->where('missions.0.due_at', '2026-11-09T18:00:00-03:00'));
        $this->get('/app/missoes');
        $this->assertDatabaseCount('task_occurrences', 1);

        $this->travelTo(Carbon::parse('2026-11-09 21:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'expired'));

        $this->travelTo(Carbon::parse('2026-11-14 13:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 2)->where('missions.0.status', 'expired')
            ->where('missions.1.due_at', '2026-11-14T12:00:00-03:00')->where('missions.1.status', 'pending'));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->has('childMissions.missions', 2)->where('childMissions.missions.0.status', 'pending')
            ->where('childMissions.missions.1.status', 'expired'));
        $this->assertDatabaseCount('task_occurrences', 2);
    }

    public function test_missions_sort_by_deadline_and_filter_by_frequency(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian)->post('/guardian/tasks', $this->task($child, [
            'title' => 'Mensal', 'frequency' => 'monthly', 'month_day' => 31,
        ]))->assertSessionHasNoErrors();
        $this->post('/guardian/tasks', $this->task($child, [
            'title' => 'Semanal', 'frequency' => 'weekly', 'weekly_day' => 7,
        ]))->assertSessionHasNoErrors();
        $this->post('/guardian/tasks', $this->task($child))->assertSessionHasNoErrors();

        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 3)
            ->where('missions.0.title', 'Arrumar a cama')
            ->where('missions.1.due_on', '2026-11-15')
            ->where('missions.2.due_on', '2026-11-30'));

        $this->get('/app/missoes?frequency=weekly')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.title', 'Semanal'));
    }

    public function test_dashboard_shows_a_same_day_mission_registered_after_its_deadline(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian)->post('/guardian/tasks', $this->task($child, [
            'title' => 'Arruma a cama', 'weekday_time' => '07:30',
        ]))->assertRedirect('/guardian/tasks');
        $this->post('/guardian/tasks', $this->task($child, [
            'title' => 'Prazo anterior ao início', 'frequency' => 'monthly', 'month_day' => 5,
        ]))->assertRedirect('/guardian/tasks');

        $this->as($child)->get('/app')->assertInertia(fn (Assert $page) => $page
            ->has('childMissions.missions', 1)->where('progress.0.expired', 1)
            ->where('childMissions.missions.0.title', 'Arruma a cama')
            ->where('childMissions.missions.0.status', 'expired'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.status', 'expired'));
        $this->assertDatabaseCount('task_occurrences', 1);
    }

    public function test_annual_leap_day_uses_february_28_in_a_common_year(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian)->post('/guardian/tasks', $this->task($child, [
            'frequency' => 'yearly', 'year_month' => 2, 'month_day' => 29,
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('task_occurrences', 0);

        $this->travelTo(Carbon::parse('2027-02-01 13:00:00', 'UTC'));
        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.due_on', '2027-02-28'));
    }

    public function test_tasks_are_restricted_to_linked_children(): void
    {
        [$guardian, $child] = $this->family();
        [$otherGuardian, $otherChild] = $this->family(2);

        $this->as($guardian)->post('/guardian/tasks', $this->task($otherChild))->assertSessionHasErrors('child_ids');
        $this->assertDatabaseCount('task_templates', 0);
        $this->post('/guardian/tasks', $this->task($child))->assertSessionHasNoErrors();
        $task = DB::table('task_templates')->value('id');
        $this->get('/app/missoes')->assertForbidden();
        $this->as($otherGuardian)->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
        $this->patch("/guardian/tasks/{$task}", $this->task($otherChild))->assertNotFound();
        $this->delete("/guardian/tasks/{$task}")->assertNotFound();
        $this->as($otherChild)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page->has('missions', 0));
        $this->post('/guardian/tasks', $this->task($otherChild))->assertForbidden();
    }

    public function test_editing_preserves_open_missions_and_soft_deletion_stops_future_missions(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian)->post('/guardian/tasks', $this->task($child, [
            'frequency' => 'weekly', 'weekly_day' => 3,
        ]))->assertSessionHasNoErrors();
        $task = DB::table('task_templates')->value('id');
        $this->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.can_manage', true)->where('tasks.0.child_ids.0', $child->id));
        $this->patch("/guardian/tasks/{$task}", $this->task($child, [
            'title' => 'Organizar a cama',
            'points' => 15,
            'daily_days' => [1, 3, 4, 5],
        ]))->assertSessionHasNoErrors()->assertRedirect('/guardian/tasks');

        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.title', 'Arrumar a cama')->where('missions.0.points', 10));
        $this->travelTo(Carbon::parse('2026-11-12 13:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 2)->where('missions.0.status', 'expired')
            ->where('missions.1.title', 'Organizar a cama')->where('missions.1.points', 15));

        $this->as($guardian)->delete("/guardian/tasks/{$task}")->assertRedirect('/guardian/tasks');
        $this->assertSoftDeleted('task_templates', ['id' => $task]);
        $this->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page->has('missions', 2));
        $this->travelTo(Carbon::parse('2026-11-13 13:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 2)->where('missions.1.status', 'expired'));
        $this->assertDatabaseCount('task_occurrences', 2);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['account_version' => $user->session_version]);
    }

    private function task(User $child, array $overrides = []): array
    {
        return [
            'title' => 'Arrumar a cama',
            'criteria' => 'Coberta dobrada.',
            'points' => 10,
            'frequency' => 'daily',
            'starts_on' => '2026-11-09',
            'weekday_time' => '18:00',
            'weekend_time' => '12:00',
            'daily_days' => [1, 6],
            'child_ids' => [$child->id],
            'photo_mode' => 'optional',
            ...$overrides,
        ];
    }

    private function family(int $number = 1): array
    {
        $family = Family::create(['name' => 'Família Teste', 'timezone' => 'America/Bahia']);
        $guardian = $this->user($family, User::GUARDIAN, '+5571000000'.$number.'01');
        $child = $this->user($family, User::CHILD, '+5571000000'.$number.'02');
        DB::table('children')->insert([
            'user_id' => $child->id,
            'family_id' => $family->id,
            'birth_date' => encrypt('2013-01-01'),
        ]);
        DB::table('guardian_child_links')->insert([
            'family_id' => $family->id,
            'guardian_id' => $guardian->id,
            'child_id' => $child->id,
            'authorized_at' => now(),
        ]);

        return [$guardian, $child];
    }

    private function user(Family $family, string $role, string $phone): User
    {
        return User::create([
            'family_id' => $family->id,
            'name' => 'Perfil Teste',
            'role' => $role,
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
        ])->refresh();
    }
}
