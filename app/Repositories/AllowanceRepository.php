<?php

namespace App\Repositories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AllowanceRepository
{
    public function currentRate(string $familyId, DateTimeInterface|string|null $at = null): ?object
    {
        return $this->rates($familyId)->when($at !== null, fn (Builder $query) => $query->where('effective_at', '<=', $at))->first();
    }

    public function rateHistory(string $familyId): Collection
    {
        return $this->rates($familyId)->get(['id', 'cents_per_point', 'effective_at']);
    }

    public function hasRate(string $familyId): bool
    {
        return $this->rates($familyId)->exists();
    }

    public function insertRate(array $attributes): int
    {
        return DB::table('conversion_rates')->insertGetId($attributes);
    }

    public function valueExistingCredits(string $familyId, int $rateId, int $cents): void
    {
        DB::table('point_credits')->whereNull('conversion_rate_id')
            ->whereIn('occurrence_id', DB::table('task_occurrences')->where('family_id', $familyId)->select('id'))
            ->update(['conversion_rate_id' => $rateId, 'amount_cents' => DB::raw('CAST(points AS BIGINT) * '.$cents)]);
    }

    public function insertCredit(array $attributes): void
    {
        DB::table('point_credits')->insert($attributes);
    }

    private function credits(User $child): Builder
    {
        return DB::table('point_credits as credits')->join('task_occurrences as missions', 'missions.id', '=', 'credits.occurrence_id')
            ->where('missions.family_id', $child->family_id)->where('missions.child_id', $child->id)
            ->select('credits.*', 'missions.title');
    }

    public function earnedPoints(User $child): int
    {
        return (int) $this->credits($child)->sum('credits.points');
    }

    public function availableCredits(User $child, ?DateTimeInterface $before = null): Collection
    {
        return $this->credits($child)->whereNotIn('credits.id', DB::table('payout_items')->select('credit_id'))
            ->when($before, fn (Builder $query) => $query->where('approved_at', '<', $before))->get();
    }

    public function creditsSince(User $child, DateTimeInterface $since): Collection
    {
        return $this->credits($child)->where('approved_at', '>=', $since)
            ->orderByDesc('approved_at')->orderByDesc('credits.id')->get();
    }

    public function payoutHistory(User $child): Collection
    {
        return $this->payouts($child)->latest('created_at')->latest('id')->get();
    }

    public function manualPayoutId(User $child, string $requestId): ?string
    {
        return $this->payouts($child)->where('request_id', $requestId)->value('id');
    }

    public function payout(User $child, string $id): object
    {
        return $this->payouts($child)->where('id', $id)->firstOrFail();
    }

    public function markPaid(User $child, string $id, User $guardian, DateTimeInterface $paidAt): void
    {
        $this->payouts($child)->where('id', $id)->update(['paid_at' => $paidAt, 'paid_by' => $guardian->id]);
    }

    public function payoutItems(User $child, Collection $payoutIds): Collection
    {
        return DB::table('payout_items as items')
            ->joinSub($this->credits($child), 'entries', 'entries.id', '=', 'items.credit_id')
            ->whereIn('items.payout_id', $payoutIds)->orderByDesc('entries.approved_at')
            ->get(['items.payout_id', 'entries.id', 'entries.title', 'entries.points', 'entries.amount_cents'])
            ->groupBy('payout_id');
    }

    public function insertPayout(array $attributes): void
    {
        DB::table('payouts')->insert($attributes);
    }

    public function insertPayoutItems(array $items): void
    {
        DB::table('payout_items')->insert($items);
    }

    public function lastSettledMonth(User $child): ?string
    {
        return DB::table('settlement_runs')->where('child_id', $child->id)->max('competence');
    }

    public function recordSettlement(array $attributes): void
    {
        DB::table('settlement_runs')->insert($attributes);
    }

    public function databaseTime(): ?string
    {
        return DB::getDriverName() === 'pgsql' ? DB::scalar('select clock_timestamp()') : null;
    }

    private function rates(string $familyId): Builder
    {
        return DB::table('conversion_rates')->where('family_id', $familyId)->latest('effective_at')->latest('id');
    }

    private function payouts(User $child): Builder
    {
        return DB::table('payouts')->where('child_id', $child->id);
    }
}
