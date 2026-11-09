<?php

namespace App\Http\Controllers;

use App\Services\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class InboxController
{
    public function __construct(private readonly InboxService $inbox) {}

    public function show(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Inbox', ['events' => $this->inbox->events($request->user())]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->inbox->unread($request->user())], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function read(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:30'], 'ids.*' => ['required', 'uuid']]);
        $this->inbox->markRead($request->user(), $data['ids']);

        return redirect()->route('inbox');
    }
}
