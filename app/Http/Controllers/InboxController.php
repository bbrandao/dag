<?php

namespace App\Http\Controllers;

use App\Services\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
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

        $user = $request->user();
        $unreadOnly = $request->query('filter') === 'unread';
        $page = $unreadOnly ? $this->inbox->unreadPage($user) : null;

        return Inertia::render('Inbox', [
            'events' => fn () => $page['events'] ?? $this->inbox->events($user),
            'pagination' => ['previous' => $page['previous'] ?? null, 'next' => $page['next'] ?? null],
            'noticeSummary' => fn () => $this->inbox->summary($user),
            'unreadOnly' => $unreadOnly,
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->inbox->unread($request->user())], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->inbox->summary($request->user()), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function read(Request $request): RedirectResponse|HttpResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:30'], 'ids.*' => ['required', 'uuid'],
            'filter' => ['nullable', 'in:unread'],
        ]);
        $this->inbox->markRead($request->user(), $data['ids']);

        if ($request->expectsJson()) {
            return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
        }

        return redirect()->route('inbox', isset($data['filter']) ? ['filter' => $data['filter']] : []);
    }
}
