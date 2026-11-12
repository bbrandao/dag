<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\FamilyRepository;
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
            'progress' => $this->progress($user, $profiles, $month, $now->utc(), $childMissions['today'] ?? null),
            'earnedPoints' => $user->role === User::CHILD ? $this->accounts->earnedPoints($user) : 0,
            'childMissions' => $childMissions,
            'pushPublicKey' => $this->push->configured() ? config('dag.web_push.publicKey') : null,
        ];
    }

    private function progress(User $user, Collection $profiles, DateTimeInterface $month, DateTimeInterface $now, ?string $childToday): Collection
    {
        [$missions, $payments] = $this->dashboard->totals($user, $profiles->pluck('id'), $month, $now, $childToday);

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

    public function updateNotices(User $user, bool $enabled): bool
    {
        return $this->families->setNoticePreference($user, $enabled);
    }
}
