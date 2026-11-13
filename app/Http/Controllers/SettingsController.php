<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePointRateRequest;
use App\Models\User;
use App\Services\AllowanceLedger;
use App\Services\AllowanceOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function show(Request $request, AllowanceOverview $overview): Response
    {
        abort_unless($request->user()->role === User::GUARDIAN, 403);
        Inertia::encryptHistory();

        return Inertia::render('Settings', ['rates' => $overview->rateHistory($request->user())]);
    }

    public function saveRate(SavePointRateRequest $request, AllowanceLedger $ledger): RedirectResponse
    {
        $ledger->setRate($request->user(), (int) $request->validated('cents_per_point'));

        return redirect()->route('settings');
    }
}
