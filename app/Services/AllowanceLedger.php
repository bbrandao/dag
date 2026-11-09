<?php

namespace App\Services;

use App\Models\Family;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AllowanceLedger
{
    public function setRate(User $guardian, int $cents): void
    {
        DB::transaction(function () use ($guardian, $cents): void {
            $family = Family::whereKey($guardian->family_id)->lockForUpdate()->firstOrFail();
            $rates = DB::table('conversion_rates')->where('family_id', $family->id);
            $latest = (clone $rates)->latest('effective_at')->latest('id')->first();
            if ($latest && (int) $latest->cents_per_point === $cents) {
                return;
            }

            $id = DB::table('conversion_rates')->insertGetId([
                'family_id' => $family->id,
                'guardian_id' => $guardian->id,
                'cents_per_point' => $cents,
                'effective_at' => $latest ? $this->now($family)->utc()->addSecond()->startOfSecond() : $family->created_at,
            ]);

            if ($latest === null) {
                DB::table('point_credits')->whereNull('conversion_rate_id')
                    ->whereIn('occurrence_id', DB::table('task_occurrences')->where('family_id', $family->id)->select('id'))
                    ->update(['conversion_rate_id' => $id, 'amount_cents' => DB::raw('CAST(points AS BIGINT) * '.(int) $cents)]);
            }
        });
    }

    public function credit(object $occurrence, CarbonImmutable $now): void
    {
        $rate = DB::table('conversion_rates')->where('family_id', $occurrence->family_id)
            ->where('effective_at', '<=', $occurrence->opens_at)
            ->latest('effective_at')->latest('id')->first();

        DB::table('point_credits')->insert([
            'occurrence_id' => $occurrence->id,
            'points' => $occurrence->points,
            'approved_at' => $now,
            'conversion_rate_id' => $rate?->id,
            'amount_cents' => $rate ? $occurrence->points * $rate->cents_per_point : null,
        ]);
    }

    public function settle(User $child): void
    {
        DB::transaction(function () use ($child): void {
            $family = Family::whereKey($child->family_id)->lockForUpdate()->firstOrFail();
            $this->closePastMonths($child, $this->now($family));
        });
    }

    public function manual(User $child, User $guardian, string $requestId, string $preview): ?string
    {
        return DB::transaction(function () use ($child, $guardian, $requestId, $preview): ?string {
            $family = Family::whereKey($child->family_id)->lockForUpdate()->firstOrFail();
            $previous = DB::table('payouts')->where('child_id', $child->id)->where('request_id', $requestId)->value('id');
            if ($previous !== null) {
                return $previous;
            }

            $now = $this->now($family);
            $this->closePastMonths($child, $now);
            $credits = $this->available($child)->get();
            if ($credits->isEmpty() || $credits->contains(fn ($credit) => $credit->amount_cents === null)
                || ! hash_equals($this->fingerprint($credits), $preview)) {
                return null;
            }

            return $this->createPayout($child, $credits, $now, null, $guardian->id, $requestId);
        });
    }

    public function markPaid(User $child, User $guardian, string $payout): void
    {
        DB::transaction(function () use ($child, $guardian, $payout): void {
            $family = Family::whereKey($child->family_id)->lockForUpdate()->firstOrFail();
            $query = DB::table('payouts')->where('child_id', $child->id)->where('id', $payout);
            $account = (clone $query)->firstOrFail();

            if ($account->paid_at === null) {
                $query->update(['paid_at' => $this->now($family)->utc(), 'paid_by' => $guardian->id]);
            }
        });
    }

    public function now(Family $family): CarbonImmutable
    {
        if (DB::getDriverName() === 'pgsql') {
            return CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->setTimezone($family->timezone);
        }

        return CarbonImmutable::now($family->timezone);
    }

    public function credits(User $child): Builder
    {
        return DB::table('point_credits as credits')->join('task_occurrences as missions', 'missions.id', '=', 'credits.occurrence_id')
            ->where('missions.family_id', $child->family_id)->where('missions.child_id', $child->id)
            ->select('credits.*', 'missions.title');
    }

    public function available(User $child): Builder
    {
        return $this->credits($child)->whereNotIn('credits.id', DB::table('payout_items')->select('credit_id'));
    }

    public function fingerprint(Collection $credits): string
    {
        return hash('sha256', $credits->sortBy('id')->map(fn ($credit) => "{$credit->id}:{$credit->amount_cents}")->implode(','));
    }

    private function closePastMonths(User $child, CarbonImmutable $now): void
    {
        if (! DB::table('conversion_rates')->where('family_id', $child->family_id)->exists()) {
            return;
        }

        $runs = DB::table('settlement_runs')->where('child_id', $child->id);
        $last = (clone $runs)->max('competence');
        $month = $last
            ? CarbonImmutable::parse($last, $now->timezone)->addMonth()
            : $child->created_at->toImmutable()->setTimezone($now->timezone)->startOfMonth();

        while ($month->lt($now->startOfMonth())) {
            $cutoff = $month->addMonth();
            $credits = $this->available($child)->where('approved_at', '<', $cutoff->utc())->get();
            if ($credits->contains(fn ($credit) => $credit->amount_cents === null)) {
                return;
            }

            $competence = $month->toDateString();
            $payout = $credits->isEmpty() ? null : $this->createPayout($child, $credits, $now, $competence);
            DB::table('settlement_runs')->insert([
                'child_id' => $child->id,
                'competence' => $competence,
                'cutoff_at' => $cutoff->utc(),
                'payout_id' => $payout,
            ]);
            $month = $cutoff;
        }
    }

    private function createPayout(User $child, Collection $credits, CarbonImmutable $now, ?string $competence, ?string $guardian = null, ?string $request = null): string
    {
        $id = (string) Str::uuid();
        DB::table('payouts')->insert([
            'id' => $id, 'child_id' => $child->id, 'created_at' => $now->utc(), 'competence' => $competence,
            'created_by' => $guardian, 'request_id' => $request,
            'points' => $credits->sum('points'), 'amount_cents' => $credits->sum('amount_cents'),
        ]);

        foreach ($credits->chunk(500) as $batch) {
            DB::table('payout_items')->insert($batch->map(fn ($credit) => ['credit_id' => $credit->id, 'payout_id' => $id])->all());
        }

        return $id;
    }
}
