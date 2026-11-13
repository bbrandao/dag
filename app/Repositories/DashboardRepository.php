<?php

namespace App\Repositories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DashboardRepository
{
    public function totals(User $user, Collection $ids, DateTimeInterface $month, DateTimeInterface $now, ?string $childToday = null): array
    {
        $visible = $childToday === null ? '' : " AND (missions.frequency <> 'monthly' OR missions.due_on <= ? OR missions.status <> 'pending')";
        $openBindings = $childToday === null ? [$now, $now] : [$now, $now, $childToday];
        $missions = DB::table('task_occurrences as missions')
            ->leftJoin('point_credits as credits', 'credits.occurrence_id', '=', 'missions.id')
            ->leftJoin('payout_items as items', 'items.credit_id', '=', 'credits.id')
            ->where('missions.family_id', $user->family_id)->whereIn('missions.child_id', $ids)
            ->groupBy('missions.child_id')->select('missions.child_id')
            ->selectRaw("SUM(CASE WHEN missions.status IN ('pending', 'adjustment') AND missions.opens_at <= ? AND missions.due_at > ?{$visible} THEN 1 ELSE 0 END) AS open_count", $openBindings)
            ->selectRaw("SUM(CASE WHEN missions.status = 'in_review' THEN 1 ELSE 0 END) AS review_count")
            ->selectRaw("SUM(CASE WHEN missions.status IN ('pending', 'adjustment') AND missions.due_at >= ? AND missions.due_at <= ? THEN 1 ELSE 0 END) AS expired_count", [$month, $now])
            ->selectRaw('SUM(CASE WHEN credits.approved_at >= ? THEN 1 ELSE 0 END) AS approved_count', [$month])
            ->selectRaw('SUM(CASE WHEN credits.approved_at >= ? THEN COALESCE(credits.amount_cents, 0) ELSE 0 END) AS month_cents', [$month])
            ->selectRaw('SUM(CASE WHEN items.credit_id IS NULL THEN COALESCE(credits.points, 0) ELSE 0 END) AS available_points')
            ->selectRaw('SUM(CASE WHEN items.credit_id IS NULL THEN COALESCE(credits.amount_cents, 0) ELSE 0 END) AS available_cents')
            ->selectRaw('SUM(CASE WHEN credits.id IS NOT NULL AND credits.amount_cents IS NULL AND items.credit_id IS NULL THEN 1 ELSE 0 END) AS unpriced_count')
            ->selectRaw('SUM(CASE WHEN credits.approved_at >= ? AND credits.amount_cents IS NULL THEN 1 ELSE 0 END) AS month_unpriced_count', [$month])
            ->get()->keyBy('child_id');
        $payments = DB::table('payouts')->whereIn('child_id', $ids)
            ->groupBy('child_id')->select('child_id')
            ->selectRaw('SUM(CASE WHEN paid_at IS NULL THEN amount_cents ELSE 0 END) AS pending_cents')
            ->selectRaw('SUM(CASE WHEN paid_at >= ? THEN amount_cents ELSE 0 END) AS paid_this_month', [$month])
            ->get()->keyBy('child_id');

        return [$missions, $payments];
    }

    public function periodTotals(User $guardian, Collection $ids, array $periods, DateTimeInterface $now): Collection
    {
        $query = DB::table('task_occurrences as missions')
            ->leftJoin('point_credits as credits', 'credits.occurrence_id', '=', 'missions.id')
            ->where('missions.family_id', $guardian->family_id)->whereIn('missions.child_id', $ids)
            ->where('missions.opens_at', '<=', $now)
            ->where(fn ($query) => $query
                ->whereBetween('missions.due_at', $periods['month'])
                ->orWhereBetween('missions.due_at', $periods['week']))
            ->groupBy('missions.child_id')->select('missions.child_id');

        foreach ($periods as $period => [$start, $end]) {
            $range = 'missions.due_at >= ? AND missions.due_at < ?';
            $query
                ->selectRaw("SUM(CASE WHEN {$range} AND missions.status IN ('pending', 'adjustment') AND missions.due_at > ? THEN 1 ELSE 0 END) AS {$period}_open", [$start, $end, $now])
                ->selectRaw("SUM(CASE WHEN {$range} AND missions.status IN ('pending', 'adjustment') AND missions.due_at <= ? THEN 1 ELSE 0 END) AS {$period}_expired", [$start, $end, $now])
                ->selectRaw("SUM(CASE WHEN {$range} AND missions.status = 'approved' THEN 1 ELSE 0 END) AS {$period}_approved", [$start, $end])
                ->selectRaw("SUM(CASE WHEN {$range} AND missions.status = 'in_review' THEN 1 ELSE 0 END) AS {$period}_review", [$start, $end])
                ->selectRaw("SUM(CASE WHEN {$range} THEN missions.points ELSE 0 END) AS {$period}_possible", [$start, $end])
                ->selectRaw("SUM(CASE WHEN {$range} THEN COALESCE(credits.points, 0) ELSE 0 END) AS {$period}_earned", [$start, $end]);
        }

        return $query->get()->keyBy('child_id');
    }
}
