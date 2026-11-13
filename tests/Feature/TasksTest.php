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
        $this->as($guardian)->get('/guardian/tasks/nova')->assertInertia(fn (Assert $page) => $page
            ->component('NewTask')->where('children.0.id', $child->id)->missing('tasks'));
        $this->from('/guardian/tasks/nova')->post('/guardian/tasks?from=new', $this->task($child, ['title' => '']))
            ->assertRedirect('/guardian/tasks/nova')->assertSessionHasErrors('title');
        $this->post('/guardian/tasks?from=new', $this->task($child))->assertRedirect('/guardian/tasks/nova')->assertSessionHasNoErrors();
        $this->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page->component('Tasks')->has('tasks', 1));

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
            ->has('missions', 2)->where('missions.1.status', 'expired')
            ->where('missions.0.due_at', '2026-11-14T12:00:00-03:00')->where('missions.0.status', 'pending'));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->has('childMissions.missions', 2)->where('childMissions.missions.0.status', 'pending')
            ->where('childMissions.missions.1.status', 'expired'));
        $this->assertDatabaseCount('task_occurrences', 2);
    }

    public function test_missions_sort_by_frequency_before_deadline_and_filter_by_frequency(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian)->post('/guardian/tasks', $this->task($child, [
            'title' => 'Mensal', 'frequency' => 'monthly', 'month_day' => 31, 'weekday_time' => '19:00',
        ]))->assertSessionHasNoErrors();
        $this->post('/guardian/tasks', $this->task($child, [
            'title' => 'Semanal', 'frequency' => 'weekly', 'weekly_day' => 7,
        ]))->assertSessionHasNoErrors();
        $this->post('/guardian/tasks', $this->task($child))->assertSessionHasNoErrors();

        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 2)
            ->where('missions.0.title', 'Arrumar a cama')
            ->where('missions.1.due_on', '2026-11-15'));

        $this->get('/app/missoes?frequency=weekly')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.title', 'Semanal'));
        $this->get('/app/missoes?frequency=monthly')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.due_on', '2026-11-30'));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->has('childMissions.missions', 2)->where('progress.0.open', 2));
        $this->travelTo(Carbon::parse('2026-11-30 13:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->where('missions', fn ($missions) => collect($missions)->last()['title'] === 'Mensal'));
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
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->where('progress.0.periods.day.expired', 1)->where('progress.0.periods.day.possible', 10)
            ->where('progress.0.periods.week.expired', 1)->where('progress.0.periods.month.expired', 1));

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
        $this->get("/guardian/tasks/{$task}/editar")->assertNotFound();
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
        $edit = "/guardian/tasks/{$task}/editar";
        $this->get($edit)->assertInertia(fn (Assert $page) => $page->component('NewTask')
            ->where('task.id', $task)->where('task.schedule.day', 3)->where('task.child_ids.0', $child->id));
        $this->patch("/guardian/tasks/{$task}?from=edit", $this->task($child, [
            'title' => 'Organizar a cama',
            'points' => 15,
            'daily_days' => [1, 3, 4, 5],
        ]))->assertSessionHasNoErrors()->assertRedirect($edit);

        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 1)->where('missions.0.title', 'Arrumar a cama')->where('missions.0.points', 10));
        $this->travelTo(Carbon::parse('2026-11-12 13:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 2)->where('missions.1.status', 'expired')
            ->where('missions.0.title', 'Organizar a cama')->where('missions.0.points', 15));

        $this->as($guardian)->deleteJson("/guardian/tasks/{$task}")->assertExactJson(['deleted' => true]);
        $this->assertSoftDeleted('task_templates', ['id' => $task]);
        $this->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page->has('missions', 2));
        $this->travelTo(Carbon::parse('2026-11-13 13:00:00', 'UTC'));
        $this->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->has('missions', 2)->where('missions.1.status', 'expired'));
        $this->assertDatabaseCount('task_occurrences', 2);
    }

    public function test_guardian_dashboard_includes_projected_recurrences_without_creating_missions(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian);

        foreach ([
            ['title' => 'Diária', 'daily_days' => [1, 3, 6]],
            ['title' => 'Semanal', 'frequency' => 'weekly', 'weekly_day' => 6, 'points' => 20],
            ['title' => 'Mensal', 'frequency' => 'monthly', 'points' => 30],
            ['title' => 'Anual futura', 'frequency' => 'yearly', 'year_month' => 11, 'month_day' => 20,
                'starts_on' => '2026-11-19', 'points' => 40],
            ['title' => 'Fora do mês', 'starts_on' => '2026-12-01'],
        ] as $attributes) {
            $this->post('/guardian/tasks', $this->task($child, $attributes))->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('task_occurrences', 3);
        $this->get('/app')->assertInertia(fn (Assert $page) => $page
            ->where('progress.0.periods.day.open', 1)->where('progress.0.periods.day.possible', 10)
            ->where('progress.0.periods.week.open', 4)->where('progress.0.periods.week.possible', 50)
            ->where('progress.0.periods.month.open', 15)->where('progress.0.periods.month.possible', 230));
        $this->assertDatabaseCount('task_occurrences', 3);
    }

    public function test_lists_sort_by_frequency_local_deadline_points_and_creation_time(): void
    {
        [$guardian, $child] = $this->family();
        $this->as($guardian);
        foreach ([
            ['title' => 'Zeta', 'points' => 20],
            ['title' => 'Alfa', 'points' => 20],
            ['title' => 'Semanal', 'points' => 20, 'frequency' => 'weekly', 'weekly_day' => 1],
            ['title' => 'Mais pontos', 'points' => 25],
            ['title' => 'Semanal mais cedo', 'points' => 50, 'frequency' => 'weekly', 'weekly_day' => 1, 'weekday_time' => '16:00'],
            ['title' => 'Mais cedo na semana', 'weekday_time' => '17:00', 'weekend_time' => '13:00'],
            ['title' => 'Mais cedo no sábado', 'weekday_time' => '19:00', 'weekend_time' => '11:00'],
        ] as $attributes) {
            $this->post('/guardian/tasks', $this->task($child, $attributes))->assertSessionHasNoErrors();
            $this->travel(1)->seconds();
        }

        $ordered = ['Mais cedo na semana', 'Mais pontos', 'Zeta', 'Alfa', 'Mais cedo no sábado', 'Semanal mais cedo', 'Semanal'];
        $this->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->all() === $ordered));
        $this->get('/guardian/revisoes?date=2026-11-09')->assertInertia(fn (Assert $page) => $page
            ->where('missions', fn ($missions) => collect($missions)->pluck('title')->all() === $ordered));
        $this->as($child)->get('/app/missoes')->assertInertia(fn (Assert $page) => $page
            ->where('missions', fn ($missions) => collect($missions)->pluck('title')->all() === $ordered));

        $this->travelTo(Carbon::parse('2026-11-14 13:00:00', 'UTC'));
        $this->as($guardian)->get('/guardian/tasks')->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.title', 'Mais cedo no sábado')->where('tasks.4.title', 'Mais cedo na semana')
            ->where('tasks.5.title', 'Semanal mais cedo'));
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
