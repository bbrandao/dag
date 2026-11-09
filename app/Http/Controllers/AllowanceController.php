<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AllowanceLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AllowanceController
{
    public function show(Request $request, AllowanceLedger $ledger, ?string $child = null): Response
    {
        $user = $request->user();
        $isGuardian = $child !== null;
        $profile = $isGuardian ? $this->linkedChild($user, $child) : $user;
        abort_unless($profile->role === User::CHILD, 403);
        $family = $profile->family;
        $now = $ledger->now($family);
        $month = $now->startOfMonth();
        $rates = DB::table('conversion_rates')->where('family_id', $family->id)
            ->latest('effective_at')->latest('id')->get(['id', 'cents_per_point', 'effective_at']);
        $available = $ledger->available($profile)->get();
        $preview = $available->filter(fn ($credit) => CarbonImmutable::parse($credit->approved_at, 'UTC')->gte($month));
        $credits = $ledger->credits($profile)->where('approved_at', '>=', $month->subMonths(5)->utc())
            ->orderByDesc('approved_at')->orderByDesc('credits.id')->get();
        $payouts = DB::table('payouts')->where('child_id', $profile->id)->latest('created_at')->latest('id')->get();
        $items = DB::table('payout_items as items')
            ->joinSub($ledger->credits($profile), 'entries', 'entries.id', '=', 'items.credit_id')
            ->whereIn('items.payout_id', $payouts->pluck('id'))->orderByDesc('entries.approved_at')
            ->get(['items.payout_id', 'entries.id', 'entries.title', 'entries.points', 'entries.amount_cents'])
            ->groupBy('payout_id');
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
        Inertia::encryptHistory();

        return Inertia::render('Allowance', [
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
                'request_id' => (string) Str::uuid(), 'fingerprint' => $ledger->fingerprint($preview),
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
        ]);
    }

    public function rate(Request $request, AllowanceLedger $ledger, string $child): RedirectResponse
    {
        $this->linkedChild($request->user(), $child);
        $data = $request->validate(['cents_per_point' => ['required', 'integer', 'between:1,100000']], [
            'cents_per_point.between' => 'Informe de 1 a 100.000 centavos por ponto.',
        ]);
        $ledger->setRate($request->user(), (int) $data['cents_per_point']);

        return redirect()->route('allowance.guardian', $child);
    }

    public function close(Request $request, AllowanceLedger $ledger, string $child): RedirectResponse
    {
        $profile = $this->linkedChild($request->user(), $child);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'fingerprint' => ['required', 'regex:/^[0-9a-f]{64}$/D']]);
        $account = $ledger->manual($profile, $request->user(), $data['request_id'], $data['fingerprint']);
        $redirect = redirect()->route('allowance.guardian', $child);

        return $account ? $redirect : $redirect->withErrors(['fingerprint' => 'O saldo mudou. Confira os valores e confirme novamente.']);
    }

    public function pay(Request $request, AllowanceLedger $ledger, string $child, string $payout): RedirectResponse
    {
        $ledger->markPaid($this->linkedChild($request->user(), $child), $request->user(), $payout);

        return redirect()->route('allowance.guardian', $child);
    }

    private function linkedChild(User $guardian, string $child): User
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        return User::whereKey($child)->where('family_id', $guardian->family_id)->where('role', User::CHILD)
            ->whereIn('id', DB::table('guardian_child_links')->where('family_id', $guardian->family_id)
                ->where('guardian_id', $guardian->id)->where('active', true)->select('child_id'))
            ->firstOrFail();
    }
}
