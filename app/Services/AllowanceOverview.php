<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Repositories\FamilyRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AllowanceOverview
{
    public function __construct(
        private readonly AllowanceRepository $accounts,
        private readonly FamilyRepository $families,
        private readonly AllowanceLedger $ledger,
    ) {}

    public function data(User $viewer, ?string $child = null): array
    {
        $isGuardian = $child !== null;
        $profile = $isGuardian ? $this->linkedChild($viewer, $child) : $viewer;
        abort_unless($profile->role === User::CHILD, 403);
        $family = $profile->family;
        $now = $this->ledger->now($family);
        $month = $now->startOfMonth();
        $rates = $this->accounts->rateHistory($family->id);
        $available = $this->accounts->availableCredits($profile);
        $preview = $available->filter(fn ($credit) => CarbonImmutable::parse($credit->approved_at, 'UTC')->gte($month));
        $credits = $this->accounts->creditsSince($profile, $month->subMonths(5)->utc());
        $payouts = $this->accounts->payoutHistory($profile);
        $items = $this->accounts->payoutItems($profile, $payouts->pluck('id'));
        $date = fn ($value) => CarbonImmutable::parse($value, 'UTC')->setTimezone($family->timezone);
        $creditMonths = $credits->groupBy(fn ($credit) => $date($credit->approved_at)->format('Y-m'));
        $paidMonths = $payouts->whereNotNull('paid_at')->groupBy(fn ($payout) => $date($payout->paid_at)->format('Y-m'));
        $months = collect(range(0, 5))->map(function (int $offset) use ($month, $creditMonths, $paidMonths) {
            $key = $month->subMonths($offset)->format('Y-m');
            $earned = $creditMonths->get($key, collect());

            return [
                'month' => $key, 'points' => $earned->sum('points'), 'earned_cents' => $earned->sum('amount_cents'),
                'unpriced' => $earned->contains(fn ($credit) => $credit->amount_cents === null),
                'paid_cents' => $paidMonths->get($key, collect())->sum('amount_cents'),
            ];
        });

        return [
            'child' => $profile->only(['id', 'name']),
            'isGuardian' => $isGuardian,
            'availablePoints' => $available->sum('points'),
            'availableCents' => $available->sum('amount_cents'),
            'unpriced' => $available->contains(fn ($credit) => $credit->amount_cents === null),
            'pendingCents' => $payouts->whereNull('paid_at')->sum('amount_cents'),
            'paidCents' => $payouts->whereNotNull('paid_at')->sum('amount_cents'),
            'rates' => $isGuardian ? $rates->map(fn ($rate) => [
                'id' => $rate->id, 'cents_per_point' => (int) $rate->cents_per_point, 'effective_at' => $date($rate->effective_at)->format('d/m/Y H:i:s'),
            ]) : [],
            'preview' => $isGuardian && $preview->isNotEmpty() && ! $preview->contains(fn ($credit) => $credit->amount_cents === null) ? [
                'request_id' => (string) Str::uuid(), 'fingerprint' => $this->ledger->fingerprint($preview),
                'points' => $preview->sum('points'), 'amount_cents' => $preview->sum('amount_cents'),
            ] : null,
            'months' => $months,
            'credits' => $credits->take(30)->map(fn ($credit) => [
                'id' => $credit->id, 'title' => $credit->title, 'points' => $credit->points,
                'amount_cents' => $credit->amount_cents, 'approved_at' => $date($credit->approved_at)->format('d/m/Y H:i'),
            ]),
            'payouts' => $payouts->map(fn ($payout) => [
                'id' => $payout->id, 'competence' => $payout->competence,
                'points' => $payout->points, 'amount_cents' => $payout->amount_cents,
                'created_at' => $date($payout->created_at)->format('d/m/Y H:i'),
                'paid_at' => $payout->paid_at ? $date($payout->paid_at)->format('d/m/Y H:i') : null,
                'items' => $items->get($payout->id, collect()),
            ]),
        ];
    }

    public function linkedChild(User $guardian, string $child): User
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        return $this->families->linkedChild($guardian, $child);
    }
}
