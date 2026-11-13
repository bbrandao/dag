<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePointRateRequest;
use App\Services\AllowanceLedger;
use App\Services\AllowanceOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AllowanceController
{
    public function __construct(private readonly AllowanceOverview $overview) {}

    public function show(Request $request, ?string $child = null): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Allowance', $this->overview->data($request->user(), $child));
    }

    public function rate(SavePointRateRequest $request, AllowanceLedger $ledger, string $child): RedirectResponse
    {
        $this->overview->linkedChild($request->user(), $child);
        $ledger->setRate($request->user(), (int) $request->validated('cents_per_point'));

        return redirect()->route('allowance.guardian', $child);
    }

    public function close(Request $request, AllowanceLedger $ledger, string $child): RedirectResponse
    {
        $profile = $this->overview->linkedChild($request->user(), $child);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'fingerprint' => ['required', 'regex:/^[0-9a-f]{64}$/D']]);
        $account = $ledger->manual($profile, $request->user(), $data['request_id'], $data['fingerprint']);
        $redirect = redirect()->route('allowance.guardian', $child);

        return $account ? $redirect : $redirect->withErrors(['fingerprint' => 'O saldo mudou. Confira os valores e confirme novamente.']);
    }

    public function pay(Request $request, AllowanceLedger $ledger, string $child, string $payout): RedirectResponse
    {
        $ledger->markPaid($this->overview->linkedChild($request->user(), $child), $request->user(), $payout);

        return redirect()->route('allowance.guardian', $child);
    }
}
