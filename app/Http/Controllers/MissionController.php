<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MissionOverview;
use App\Services\MissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MissionController
{
    public function __construct(private readonly MissionService $missions, private readonly MissionOverview $overview) {}

    public function reviews(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Reviews', $this->overview->reviews($request->user()));
    }

    public function show(Request $request, string $mission): Response
    {
        $role = $request->routeIs('reviews.show') ? User::GUARDIAN : User::CHILD;
        Inertia::encryptHistory();

        return Inertia::render('Mission', $this->overview->details($request->user(), $mission, $role));
    }

    public function submit(Request $request, string $mission): RedirectResponse
    {
        $this->missions->requireSubmissionAccess($request->user(), $mission);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'comment' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'max:10240'],
        ], ['comment.max' => 'Escreva até 255 caracteres.', 'photo.max' => 'Escolha uma foto de até 10 MB.']);

        $this->missions->submit($request->user(), $mission, $data);

        return redirect()->route('missions.show', $mission);
    }

    public function review(Request $request, string $mission): RedirectResponse
    {
        abort_unless($request->user()->role === User::GUARDIAN, 403);
        $data = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'decision' => ['required', 'in:approved,rejected,adjustment'],
            'feedback' => ['required_if:decision,adjustment', 'nullable', 'string', 'max:255'],
        ], ['feedback.required_if' => 'Diga o que a criança precisa ajustar.', 'feedback.max' => 'Escreva até 255 caracteres.']);

        $this->missions->review($request->user(), $mission, $data);

        return redirect()->route('reviews.show', $mission);
    }

    public function reopen(Request $request, string $mission): RedirectResponse
    {
        abort_unless($request->user()->role === User::GUARDIAN, 403);
        $data = $request->validate([
            'new_due_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->missions->reopen($request->user(), $mission, $data);

        return redirect()->route('reviews.show', $mission);
    }

    public function photo(Request $request, string $mission, string $submission): StreamedResponse
    {
        $path = $this->missions->photoPath($request->user(), $mission, $submission);
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, 'missao.jpg', ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }
}
