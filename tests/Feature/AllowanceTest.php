<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Services\AllowanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-30 18:00:00', 'UTC'));
    }

    public function test_first_rate_values_existing_points_and_future_rates_preserve_open_missions(): void
    {
        [$guardian, $child, $task] = $this->family();
        $url = "/guardian/mesadas/{$child->id}";
        $first = $this->mission($task, $child);
        $this->approve($guardian, $child, $first);
        $this->assertDatabaseHas('point_credits', ['occurrence_id' => $first, 'amount_cents' => null]);

        $this->as($guardian)->post("{$url}/conversao", ['cents_per_point' => 10])->assertRedirect($url);
        $this->assertDatabaseHas('point_credits', ['occurrence_id' => $first, 'amount_cents' => 100]);
        $preview = $this->get($url)->viewData('page')['props']['preview'];
        $this->travelTo(Carbon::parse('2026-10-31 18:00:00', 'UTC'));
        $open = $this->mission($task, $child);
        $this->post("{$url}/conversao", ['cents_per_point' => 20])->assertRedirect($url);
        $this->approve($guardian, $child, $open);
        $this->assertDatabaseHas('point_credits', ['occurrence_id' => $open, 'amount_cents' => 100]);

        $this->postJson("{$url}/fechar", $preview)->assertConflict()
            ->assertJsonPath('message', 'O saldo mudou. Confira os valores e confirme novamente.');
        $this->assertDatabaseCount('payouts', 0);
        $preview = $this->get($url)->viewData('page')['props']['preview'];
        $this->postJson("{$url}/fechar", $preview)->assertExactJson(['closed' => true]);
        $this->post("{$url}/fechar", $preview)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payouts', 1);
        $payout = DB::table('payouts')->first();
        $this->assertSame(200, $payout->amount_cents);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->has('credits', 0)->where('pendingCents', 200)->where('paidCents', 0));

        $this->travelTo(Carbon::parse('2026-11-01 16:00:00', 'UTC'));
        $new = $this->mission($task, $child);
        $this->approve($guardian, $child, $new);
        $this->assertDatabaseHas('point_credits', ['occurrence_id' => $new, 'amount_cents' => 200]);
        $this->patch("{$url}/contas/{$payout->id}/paga")->assertRedirect($url);
        $paidAt = DB::table('payouts')->value('paid_at');
        $this->travel(1)->hours();
        $this->patch("{$url}/contas/{$payout->id}/paga")->assertRedirect($url);
        $this->assertSame($paidAt, DB::table('payouts')->value('paid_at'));
        $newCredit = DB::table('point_credits')->where('occurrence_id', $new)->value('id');
        DB::table('mission_submissions')->where('occurrence_id', $new)->where('decision', 'approved')
            ->update(['submitted_at' => '2026-11-01 15:45:00']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->has('credits', 1)->where('credits.0.id', $newCredit)->where('credits.0.amount_cents', 200)
            ->where('credits.0.completed_at', '01/11/2026 12:45')->where('credits.0.approved_at', '01/11/2026 13:00')
            ->where('pendingCents', 0)->where('paidCents', 200));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('rateCents', 20)->has('progress', 1)->where('progress.0.id', $child->id)
            ->where('progress.0.availablePoints', 10)->where('progress.0.availableCents', 200)->where('progress.0.approved', 1)
            ->where('progress.0.monthCents', 200)->where('progress.0.paidThisMonth', 200));
        $this->as($child)->get('/app/ganhos')->assertInertia(fn (Assert $page) => $page->component('Allowance')
            ->where('availablePoints', 10)->where('availableCents', 200)->where('paidCents', 200)
            ->where('pendingCents', 0)->has('rates', 0)->where('months.0.paid_cents', 200));
        $this->get('/app')->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('rateCents', null)->has('progress', 1)->where('progress.0.id', $child->id)
            ->where('progress.0.availablePoints', 10)->where('progress.0.pendingCents', 0));
        $this->get($url)->assertForbidden();
    }

    public function test_guardian_configures_family_point_rate_without_a_child_and_can_see_the_full_history(): void
    {
        [$guardian, $child] = $this->family();
        DB::table('guardian_child_links')->where('guardian_id', $guardian->id)->delete();
        $url = '/guardian/configuracoes';
        $this->as($guardian)->get($url)->assertInertia(fn (Assert $page) => $page->component('Settings')->has('rates', 0));
        $this->from($url)->post("{$url}/valor-do-ponto", ['cents_per_point' => 0])->assertRedirect($url)
            ->assertSessionHasErrors('cents_per_point');

        foreach ([10, 15, 20, 25] as $cents) {
            $this->travel(1)->minutes();
            $this->post("{$url}/valor-do-ponto", ['cents_per_point' => $cents])->assertRedirect($url)->assertSessionHasNoErrors();
        }

        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('rates', 4)
            ->where('rates.0.cents_per_point', 25)->where('rates.0.recorded_at', '30/10/2026 15:04:00')
            ->where('rates.3.cents_per_point', 10)->where('rates.3.recorded_at', '30/10/2026 15:01:00'));
        $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Settings',
            'X-Inertia-Partial-Data' => 'rates,errors',
        ])->assertOk()->assertJsonPath('props.rates.0.cents_per_point', 25)->assertJsonMissingPath('props.viewer');
        $this->as($child)->get($url)->assertForbidden();
        $this->post("{$url}/valor-do-ponto", ['cents_per_point' => 50])->assertForbidden();
        $this->assertDatabaseCount('conversion_rates', 4);
    }

    public function test_monthly_close_uses_original_local_cutoff_and_retries_without_duplicates(): void
    {
        [$guardian, $child, $task] = $this->family();
        $url = "/guardian/mesadas/{$child->id}";
        $this->as($guardian)->post("{$url}/conversao", ['cents_per_point' => 10]);
        $this->travelTo(Carbon::parse('2026-11-01 02:59:59', 'UTC'));
        $october = $this->mission($task, $child);
        $this->approve($guardian, $child, $october);
        $this->travelTo(Carbon::parse('2026-11-01 03:00:00', 'UTC'));
        $november = $this->mission($task, $child);
        $this->approve($guardian, $child, $november);

        $this->travelTo(Carbon::parse('2026-11-02 16:00:00', 'UTC'));
        $ledger = app(AllowanceLedger::class);
        $ledger->settle($child);
        $ledger->settle($child);
        $this->assertDatabaseHas('settlement_runs', ['child_id' => $child->id, 'competence' => '2026-10-01', 'cutoff_at' => '2026-11-01 03:00:00']);
        $this->assertDatabaseCount('payouts', 1);
        $this->assertDatabaseHas('payouts', ['competence' => '2026-10-01', 'amount_cents' => 100, 'paid_at' => null]);
        $this->assertSame($november, app(AllowanceRepository::class)->availableCredits($child)->sole()->occurrence_id);

        $preview = $this->get($url)->viewData('page')['props']['preview'];
        $this->post("{$url}/fechar", $preview)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payouts', 2);
        $this->assertDatabaseCount('payout_items', 2);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['account_version' => $user->session_version]);
    }

    private function approve(User $guardian, User $child, string $mission): void
    {
        $this->as($child)->post("/app/missoes/{$mission}/entregas", ['request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->as($guardian)->post("/guardian/revisoes/{$mission}", [
            'submission_id' => DB::table('mission_submissions')->where('occurrence_id', $mission)->value('id'),
            'decision' => 'approved',
        ])->assertSessionHasNoErrors();
    }

    private function mission(TaskTemplate $task, User $child): string
    {
        $id = (string) Str::uuid();
        $today = now('America/Bahia')->toDateString();
        DB::table('task_occurrences')->insert([
            'id' => $id, 'family_id' => $task->family_id, 'task_id' => $task->id, 'child_id' => $child->id,
            'period_start' => $today, 'due_on' => $today, 'opens_at' => now(), 'due_at' => now()->addHours(2),
            'title' => 'Tarefa teste', 'criteria' => 'Pronta.', 'points' => 10, 'frequency' => 'daily', 'photo_mode' => 'disabled',
        ]);

        return $id;
    }

    private function family(): array
    {
        $family = Family::create(['name' => 'Família Teste', 'timezone' => 'America/Bahia']);
        $people = [];
        foreach ([User::GUARDIAN => '+5571999991111', User::CHILD => '+5571999992222'] as $role => $phone) {
            $people[] = User::create([
                'family_id' => $family->id, 'name' => 'Perfil Teste', 'role' => $role, 'phone' => $phone,
                'phone_hash' => hash('sha256', $phone),
            ])->refresh();
        }
        [$guardian, $child] = $people;
        DB::table('children')->insert(['user_id' => $child->id, 'family_id' => $family->id, 'birth_date' => encrypt('2013-01-01')]);
        DB::table('guardian_child_links')->insert([
            'family_id' => $family->id, 'guardian_id' => $guardian->id, 'child_id' => $child->id, 'authorized_at' => now(),
        ]);
        $task = TaskTemplate::create([
            'family_id' => $family->id, 'title' => 'Tarefa teste', 'criteria' => 'Pronta.', 'points' => 10,
            'frequency' => 'daily', 'schedule' => ['days' => [1]], 'starts_on' => '2026-10-30',
            'weekday_time' => '20:00', 'weekend_time' => '20:00', 'photo_mode' => 'disabled',
        ]);
        $task->children()->attach($child->id, ['family_id' => $family->id, 'active' => true]);

        return [$guardian, $child, $task];
    }
}
