<?php

namespace App\Repositories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DashboardRepository
{
    public function totals(User $user, Collection $ids, DateTimeInterface $month, DateTimeInterface $now): array
    {
        $missions = DB::table('task_occurrences as missions')
            ->leftJoin('point_credits as credits', 'credits.occurrence_id', '=', 'missions.id')
            ->leftJoin('payout_items as items', 'items.credit_id', '=', 'credits.id')
            ->where('missions.family_id', $user->family_id)->whereIn('missions.child_id', $ids)
            ->groupBy('missions.child_id')->select('missions.child_id')
            ->selectRaw("SUM(CASE WHEN missions.status IN ('pending', 'adjustment') AND missions.opens_at <= ? AND missions.due_at > ? THEN 1 ELSE 0 END) AS open_count", [$now, $now])
            ->selectRaw("SUM(CASE WHEN missions.status = 'in_review' THEN 1 ELSE 0 END) AS review_count")
            ->selectRaw("SUM(CASE WHEN missions.status IN ('pending', 'adjustment') AND missions.due_at >= ? AND missions.due_at <= ? THEN 1 ELSE 0 END) AS expired_count", [$month, $now])
            ->selectRaw('SUM(CASE WHEN credits.approved_at >= ? THEN 1 ELSE 0 END) AS approved_count', [$month])
            ->selectRaw('SUM(CASE WHEN credits.approved_at >= ? THEN COALESCE(credits.amount_cents, 0) ELSE 0 END) AS month_cents', [$month])
            ->selectRaw('SUM(CASE WHEN items.credit_id IS NULL THEN COALESCE(credits.points, 0) ELSE 0 END) AS available_points')
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
}
