<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
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

    public function updateNotices(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');
        abort_unless($this->dashboard->updateNotices($request->user(), $enabled), 409);

        if ($request->expectsJson()) {
            return response()->json(['enabled' => $enabled]);
        }

        return redirect()->route('dashboard');
    }
}
