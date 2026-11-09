<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Dashboard', $this->dashboard->data($request->user()));
    }

    public function updateNotices(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $this->dashboard->updateNotices($request->user(), $request->boolean('enabled'));

        return redirect()->route('dashboard');
    }
}
