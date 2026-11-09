<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class InboxController
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $guardian = $user->role === User::GUARDIAN;
        $field = $guardian ? 'submitted_at' : 'reviewed_at';
        $events = $this->events($user)->orderByRaw('CASE WHEN reads.user_id IS NULL THEN 0 ELSE 1 END')
            ->latest($field)->latest('attempts.id')
            ->limit(30)->get(['attempts.id', 'mission.id as mission_id', 'mission.title', 'attempts.decision',
                "attempts.{$field} as occurred_at", 'reads.user_id as read_by'])
            ->map(fn (object $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'message' => $guardian ? 'Entrega enviada para revisão' : match ($event->decision) {
                    'approved' => 'Missão aprovada',
                    'adjustment' => 'O responsável pediu um ajuste',
                },
                'href' => route($guardian ? 'reviews.show' : 'missions.show', $event->mission_id, false),
                'unread' => $event->read_by === null,
                'occurred_at' => CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone($user->family->timezone)->format('d/m/Y H:i'),
            ]);
        Inertia::encryptHistory();

        return Inertia::render('Inbox', ['events' => $events]);
    }

    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $this->events($user)->whereNull('reads.user_id')->count();

        return response()->json(['unread' => $count], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function read(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:30'], 'ids.*' => ['required', 'uuid']]);
        $user = $request->user();
        $ids = $this->events($user)->whereIn('attempts.id', $data['ids'])->pluck('attempts.id');
        DB::table('inbox_reads')->insertOrIgnore($ids->map(fn (string $id) => [
            'user_id' => $user->id, 'submission_id' => $id,
        ])->all());

        return redirect()->route('inbox');
    }

    private function events(User $user): Builder
    {
        $query = DB::table('mission_submissions as attempts')
            ->join('task_occurrences as mission', 'mission.id', '=', 'attempts.occurrence_id')
            ->leftJoin('inbox_reads as reads', fn ($join) => $join
                ->on('reads.submission_id', '=', 'attempts.id')->where('reads.user_id', '=', $user->id))
            ->where('mission.family_id', $user->family_id);

        if ($user->role === User::CHILD) {
            return $query->where('mission.child_id', $user->id)->whereNotNull('attempts.reviewed_at');
        }

        return $query->whereIn('mission.child_id', DB::table('guardian_child_links')
            ->where('family_id', $user->family_id)->where('guardian_id', $user->id)
            ->where('active', true)->select('child_id'));
    }
}
