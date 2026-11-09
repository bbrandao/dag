<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $family = $user->family;
        $isGuardian = $user->role === User::GUARDIAN;
        $children = $isGuardian
            ? DB::table('guardian_child_links as links')
                ->join('users', 'users.id', '=', 'links.child_id')
                ->where('links.family_id', $user->family_id)
                ->where('links.guardian_id', $user->id)
                ->where('links.active', true)
                ->where('users.active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name'])
            : collect();
        $profiles = $isGuardian ? $children : collect([(object) $user->only(['id', 'name'])]);
        $now = now($family->timezone);
        $month = $now->copy()->startOfMonth()->utc();
        $rate = $isGuardian ? DB::table('conversion_rates')->where('family_id', $family->id)
            ->where('effective_at', '<=', $now->copy()->utc())->latest('effective_at')->latest('id')->value('cents_per_point') : null;

        Inertia::encryptHistory();

        return Inertia::render('Dashboard', [
            'name' => $user->name,
            'role' => $user->role,
            'family' => $family->name,
            'children' => $children,
            'whatsappNotices' => (bool) $user->whatsapp_notices,
            'monthLabel' => $now->locale('pt_BR')->isoFormat('MMMM [de] YYYY'),
            'rateCents' => $rate === null ? null : (int) $rate,
            'progress' => $this->progress($user, $profiles, $month, $now->utc()),
            'earnedPoints' => $user->role === User::CHILD ? (int) DB::table('point_credits')
                ->join('task_occurrences', 'task_occurrences.id', '=', 'point_credits.occurrence_id')
                ->where('task_occurrences.child_id', $user->id)->sum('point_credits.points') : 0,
        ]);
    }

    private function progress(User $user, Collection $profiles, \DateTimeInterface $month, \DateTimeInterface $now): Collection
    {
        $ids = $profiles->pluck('id');
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

        return $profiles->map(function (object $child) use ($missions, $payments): array {
            $mission = $missions->get($child->id);
            $payment = $payments->get($child->id);

            return [
                'id' => $child->id,
                'name' => $child->name,
                'open' => (int) ($mission->open_count ?? 0),
                'inReview' => (int) ($mission->review_count ?? 0),
                'expired' => (int) ($mission->expired_count ?? 0),
                'approved' => (int) ($mission->approved_count ?? 0),
                'availablePoints' => (int) ($mission->available_points ?? 0),
                'awaitingConversion' => (int) ($mission->unpriced_count ?? 0) > 0,
                'monthCents' => (int) ($mission->month_cents ?? 0),
                'monthAwaitingConversion' => (int) ($mission->month_unpriced_count ?? 0) > 0,
                'pendingCents' => (int) ($payment->pending_cents ?? 0),
                'paidThisMonth' => (int) ($payment->paid_this_month ?? 0),
            ];
        });
    }

    public function updateNotices(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $user = $request->user();
        User::whereKey($user->id)->where('active', true)->where('session_version', $user->session_version)
            ->update(['whatsapp_notices' => $request->boolean('enabled')]);

        return redirect()->route('dashboard');
    }
}
