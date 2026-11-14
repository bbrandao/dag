<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\FamilyRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

final class DashboardService
{
    public function __construct(
        private readonly FamilyRepository $families,
        private readonly AllowanceRepository $accounts,
        private readonly DashboardRepository $dashboard,
        private readonly TaskService $tasks,
        private readonly PushTransport $push,
        private readonly TaskCalendar $calendar,
    ) {}

    public function data(User $user): array
    {
        $family = $user->family;
        $isGuardian = $user->role === User::GUARDIAN;
        $children = $isGuardian
            ? $this->families->childrenForDashboard($user)
            : collect();
        $profiles = $isGuardian ? $children : collect([(object) $user->only(['id', 'name'])]);
        $now = now($family->timezone);
        $month = $now->copy()->startOfMonth()->utc();
        $rate = $isGuardian ? $this->accounts->currentRate($family->id, $now->copy()->utc())?->cents_per_point : null;
        $childMissions = $isGuardian ? null : $this->tasks->dashboardMissions($user);

        return [
            'name' => $user->name,
            'role' => $user->role,
            'family' => $family->name,
            'children' => $children,
            'whatsappNotices' => (bool) $user->whatsapp_notices,
            'monthLabel' => $now->locale('pt_BR')->isoFormat('MMMM [de] YYYY'),
            'rateCents' => $rate === null ? null : (int) $rate,
            'progress' => $this->progress($user, $profiles, $month, $now->toImmutable(), $childMissions['today'] ?? null),
            'today' => $now->toDateString(),
            'earnedPoints' => $user->role === User::CHILD ? $this->accounts->earnedPoints($user) : 0,
            'childMissions' => $childMissions,
            'pushPublicKey' => $this->push->configured() ? config('dag.web_push.publicKey') : null,
        ];
    }

    private function progress(User $user, Collection $profiles, DateTimeInterface $month, CarbonImmutable $now, ?string $childToday): Collection
    {
        $ids = $profiles->pluck('id');
        [$missions, $payments] = $this->dashboard->totals($user, $ids, $month, $now->utc(), $childToday);
        $day = $now->startOfDay();
        $week = $now->startOfWeek(CarbonInterface::MONDAY);
        $localMonth = $now->startOfMonth();
        $windows = [
            'day' => [$day, $day->addDay()],
            'week' => [$week, $week->addWeek()],
            'month' => [$localMonth, $localMonth->addMonth()],
        ];
        $periods = null;
        $projected = [];
        if ($ids->isNotEmpty()) {
            $projected = $this->calendar->projectedTotals($user->family, $ids, $now, max($windows['week'][1], $windows['month'][1]));
            $periods = $this->dashboard->periodTotals($user, $ids, array_map(
                fn (array $window) => [$window[0]->utc(), $window[1]->utc()], $windows,
            ), $now->utc());
        }

        return $profiles->map(function (object $child) use ($missions, $payments, $periods, $projected, $windows): array {
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
                'availableCents' => (int) ($mission->available_cents ?? 0),
                'awaitingConversion' => (int) ($mission->unpriced_count ?? 0) > 0,
                'monthCents' => (int) ($mission->month_cents ?? 0),
                'monthAwaitingConversion' => (int) ($mission->month_unpriced_count ?? 0) > 0,
                'pendingCents' => (int) ($payment->pending_cents ?? 0),
                'paidThisMonth' => (int) ($payment->paid_this_month ?? 0),
                'periods' => $periods === null ? null : $this->periodProgress($periods->get($child->id), $projected[$child->id] ?? [], $windows),
            ];
        });
    }

    private function periodProgress(?object $totals, array $projected, array $windows): array
    {
        $progress = [];
        foreach ($windows as $period => [$start, $end]) {
            $progress[$period] = [
                'open' => (int) ($totals->{"{$period}_open"} ?? 0),
                'approved' => (int) ($totals->{"{$period}_approved"} ?? 0),
                'expired' => (int) ($totals->{"{$period}_expired"} ?? 0),
                'inReview' => (int) ($totals->{"{$period}_review"} ?? 0),
                'earned' => (int) ($totals->{"{$period}_earned"} ?? 0),
                'possible' => (int) ($totals->{"{$period}_possible"} ?? 0),
            ];
            foreach ($projected as $day => $counts) {
                if ($day >= $start->toDateString() && $day < $end->toDateString()) {
                    foreach ($counts as $metric => $value) {
                        $progress[$period][$metric] += $value;
                    }
                }
            }
        }

        return $progress;
    }

    public function updateNotices(User $user, bool $enabled): bool
    {
        return $this->families->setNoticePreference($user, $enabled);
    }
}
