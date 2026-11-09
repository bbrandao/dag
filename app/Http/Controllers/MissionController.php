<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Family;
use App\Services\AllowanceLedger;
use App\Services\EvidencePhoto;
use App\Services\MissionNotices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function Illuminate\Support\defer;

final class MissionController
{
    public function reviews(Request $request): Response
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $missions = $this->accessible($guardian)
            ->join('users as children', 'children.id', '=', 'task_occurrences.child_id')
            ->leftJoin('mission_submissions as pending_attempt', fn ($join) => $join
                ->on('pending_attempt.occurrence_id', '=', 'task_occurrences.id')->whereNull('pending_attempt.reviewed_at'))
            ->where('children.active', true)
            ->where(function (Builder $query): void {
                $query->where('status', 'in_review')->orWhere(fn (Builder $expired) => $expired
                    ->whereIn('status', ['pending', 'adjustment'])->where('due_at', '<=', now()));
            })
            ->orderByRaw("CASE WHEN status = 'in_review' THEN 0 ELSE 1 END")
            ->orderBy('pending_attempt.submitted_at')
            ->orderBy('due_at')
            ->get(['task_occurrences.id', 'title', 'status', 'due_at', 'children.name as child_name'])
            ->map(fn (object $mission) => $this->display($mission, $guardian));

        Inertia::encryptHistory();

        return Inertia::render('Reviews', ['missions' => $missions]);
    }

    public function show(Request $request, string $mission): Response
    {
        $user = $request->user();
        $routeRole = $request->routeIs('reviews.show') ? User::GUARDIAN : User::CHILD;
        abort_unless($user->role === $routeRole, 403);
        $occurrence = $this->accessible($user)->where('id', $mission)->firstOrFail();
        $attempts = DB::table('mission_submissions')->where('occurrence_id', $mission)
            ->orderByRaw('CASE WHEN reviewed_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('submitted_at')->orderByDesc('id')
            ->get(['id', 'comment', 'decision', 'feedback', 'photo_path', 'submitted_at'])
            ->map(fn (object $attempt) => [
                'id' => $attempt->id,
                'comment' => $attempt->comment,
                'decision' => $attempt->decision,
                'feedback' => $attempt->feedback,
                'submitted_at' => $this->localTime($attempt->submitted_at, $user)->format('d/m/Y H:i'),
                'photo_url' => $attempt->photo_path ? route('missions.photo', [$mission, $attempt->id], false) : null,
            ]);
        $reopening = DB::table('mission_reopenings')->where('occurrence_id', $mission)->latest('id')->value('reason');
        Inertia::encryptHistory();

        return Inertia::render('Mission', [
            'mission' => $this->display($occurrence, $user),
            'attempts' => $attempts,
            'reopening' => $reopening,
            'requestId' => (string) Str::uuid(),
            'isGuardian' => $routeRole === User::GUARDIAN,
        ]);
    }

    public function submit(Request $request, string $mission, EvidencePhoto $photos, MissionNotices $notices): RedirectResponse
    {
        $child = $request->user();
        abort_unless($child->role === User::CHILD, 403);
        $this->accessible($child)->where('id', $mission)->firstOrFail();
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'comment' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'max:10240'],
        ], ['comment.max' => 'Escreva até 255 caracteres.', 'photo.max' => 'Escolha uma foto de até 10 MB.']);

        $photoPath = null;
        try {
            $submissionId = DB::transaction(function () use ($child, $mission, $data, $photos, $notices, &$photoPath): string {
                $occurrence = $this->accessible($child)->where('id', $mission)->lockForUpdate()->firstOrFail();
                $submissions = DB::table('mission_submissions')->where('occurrence_id', $mission);
                $duplicate = (clone $submissions)->where('request_id', $data['request_id'])->value('id');
                if ($duplicate !== null) {
                    return $duplicate;
                }

                $this->ensureCanSubmit($occurrence);
                $photo = $data['photo'] ?? null;
                if ($occurrence->photo_mode === 'required' && $photo === null) {
                    throw ValidationException::withMessages(['photo' => 'Adicione uma foto para enviar a missão.']);
                }
                if ($occurrence->photo_mode === 'disabled' && $photo !== null) {
                    throw ValidationException::withMessages(['photo' => 'Esta missão recebe apenas comentário ou confirmação.']);
                }

                $id = (string) Str::uuid7();
                if ($photo !== null) {
                    $photoPath = $photos->store($photo, $id);
                    $this->ensureCanSubmit($occurrence);
                }

                DB::table('mission_submissions')->insert([
                    'id' => $id,
                    'occurrence_id' => $mission,
                    'request_id' => $data['request_id'],
                    'comment' => $data['comment'] ?? null,
                    'photo_path' => $photoPath,
                    'submitted_at' => now(),
                ]);
                DB::table('task_occurrences')->where('id', $mission)->update(['status' => 'in_review']);
                $notices->queue($occurrence, $id, 'submitted');

                return $id;
            });
        } catch (Throwable $error) {
            if ($photoPath !== null) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $error;
        }

        defer(fn () => $notices->deliver($submissionId));

        return redirect()->route('missions.show', $mission);
    }

    public function review(Request $request, string $mission, MissionNotices $notices, AllowanceLedger $ledger): RedirectResponse
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);
        $data = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'decision' => ['required', 'in:approved,adjustment'],
            'feedback' => ['required_if:decision,adjustment', 'nullable', 'string', 'max:255'],
        ], ['feedback.required_if' => 'Diga o que a criança precisa ajustar.', 'feedback.max' => 'Escreva até 255 caracteres.']);

        DB::transaction(function () use ($guardian, $mission, $data, $notices, $ledger): void {
            $family = Family::whereKey($guardian->family_id)->lockForUpdate()->firstOrFail();
            $occurrence = $this->accessible($guardian)->where('id', $mission)->lockForUpdate()->firstOrFail();
            $submission = DB::table('mission_submissions')->where('occurrence_id', $mission)
                ->where('id', $data['submission_id']);
            $attempt = (clone $submission)->firstOrFail();
            if ($attempt->decision === $data['decision'] && $attempt->reviewer_id === $guardian->id) {
                return;
            }
            if ($attempt->reviewed_at !== null || $occurrence->status !== 'in_review') {
                throw ValidationException::withMessages(['mission' => 'Esta entrega já recebeu uma revisão.']);
            }

            $now = $ledger->now($family)->utc();
            $submission->update([
                'decision' => $data['decision'],
                'feedback' => $data['feedback'] ?? null,
                'reviewer_id' => $guardian->id,
                'reviewed_at' => $now,
            ]);
            DB::table('task_occurrences')->where('id', $mission)->update(['status' => $data['decision']]);
            if ($data['decision'] === 'approved') {
                $ledger->credit($occurrence, $now);
            }
            $notices->queue($occurrence, $attempt->id, $data['decision']);
        });

        defer(fn () => $notices->deliver($data['submission_id']));

        return redirect()->route('reviews.show', $mission);
    }

    public function reopen(Request $request, string $mission): RedirectResponse
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);
        $data = $request->validate([
            'new_due_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $newDue = $this->localTime($data['new_due_at'], $guardian)->utc();

        DB::transaction(function () use ($guardian, $mission, $newDue, $data): void {
            $occurrence = $this->accessible($guardian)->where('id', $mission)->lockForUpdate()->firstOrFail();
            $now = now();
            if ($newDue->lte($now)) {
                throw ValidationException::withMessages(['new_due_at' => 'Escolha um prazo futuro.']);
            }
            if (! in_array($occurrence->status, ['pending', 'adjustment'], true) || CarbonImmutable::parse($occurrence->due_at, 'UTC')->gt($now)) {
                throw ValidationException::withMessages(['mission' => 'Só é possível reabrir uma missão vencida que aceita entregas.']);
            }

            DB::table('mission_reopenings')->insert([
                'occurrence_id' => $mission,
                'guardian_id' => $guardian->id,
                'previous_due_at' => $occurrence->due_at,
                'new_due_at' => $newDue,
                'reason' => $data['reason'],
                'reopened_at' => $now,
            ]);
            DB::table('task_occurrences')->where('id', $mission)->update(['due_at' => $newDue]);
        });

        return redirect()->route('reviews.show', $mission);
    }

    public function photo(Request $request, string $mission, string $submission): StreamedResponse
    {
        $this->accessible($request->user())->where('id', $mission)->firstOrFail();
        $path = DB::table('mission_submissions')->where('occurrence_id', $mission)->where('id', $submission)->value('photo_path');
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, 'missao.jpg', ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    private function accessible(User $user): Builder
    {
        $query = DB::table('task_occurrences')->where('task_occurrences.family_id', $user->family_id);

        if ($user->role === User::CHILD) {
            return $query->where('task_occurrences.child_id', $user->id);
        }

        abort_unless($user->role === User::GUARDIAN, 403);

        return $query->whereIn('task_occurrences.child_id', DB::table('guardian_child_links')
            ->where('family_id', $user->family_id)->where('guardian_id', $user->id)
            ->where('active', true)->select('child_id'));
    }

    private function ensureCanSubmit(object $occurrence): void
    {
        $now = now();
        if (! in_array($occurrence->status, ['pending', 'adjustment'], true)
            || CarbonImmutable::parse($occurrence->opens_at, 'UTC')->gt($now)
            || CarbonImmutable::parse($occurrence->due_at, 'UTC')->lte($now)) {
            throw ValidationException::withMessages(['mission' => 'Esta missão não aceita envios neste momento.']);
        }
    }

    private function display(object $mission, User $user): array
    {
        $due = CarbonImmutable::parse($mission->due_at, 'UTC')->setTimezone($user->family->timezone);
        $status = $mission->status;
        if (in_array($status, ['pending', 'adjustment'], true) && $due->lte(now())) {
            $status = 'expired';
        }

        return [
            'id' => $mission->id,
            'title' => $mission->title,
            'status' => $status,
            'due' => $due->format('d/m/Y H:i'),
            'original_date' => $mission->due_on ?? null,
            'criteria' => $mission->criteria ?? null,
            'points' => $mission->points ?? null,
            'photo_mode' => $mission->photo_mode ?? null,
            'child_name' => $mission->child_name ?? null,
        ];
    }

    private function localTime(string $value, User $user): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $user->family->timezone)->setTimezone($user->family->timezone);
    }
}
