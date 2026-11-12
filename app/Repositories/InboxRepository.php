<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InboxRepository
{
    private const LIMIT = 5;

    public function markReviewUnread(string $child, string $submission): void
    {
        DB::table('inbox_reads')->where('user_id', $child)->where('submission_id', $submission)->delete();
    }

    public function __construct(private readonly FamilyRepository $families) {}

    public function recent(User $user): Collection
    {
        return $this->orderedEvents($user, false)->limit(self::LIMIT)->get();
    }

    public function unreadPreview(User $user): Collection
    {
        return $this->orderedEvents($user, true)->selectRaw('count(*) over () as unread_total')->limit(self::LIMIT)->get();
    }

    public function unreadPage(User $user): CursorPaginator
    {
        return $this->orderedEvents($user, true)->cursorPaginate(15)->appends(['filter' => 'unread']);
    }

    public function unread(User $user): int
    {
        return $this->events($user)->whereNull('reads.user_id')->count();
    }

    private function orderedEvents(User $user, bool $unreadOnly): Builder
    {
        $field = $user->role === User::GUARDIAN ? 'submitted_at' : 'reviewed_at';

        return $this->events($user)->when($unreadOnly, fn (Builder $query) => $query->whereNull('reads.user_id'))
            ->select(['attempts.id as id', 'mission.id as mission_id', 'mission.title', 'attempts.decision',
                "attempts.{$field} as occurred_at", 'reads.user_id as read_by'])
            ->latest('occurred_at')->latest('id');
    }

    public function markRead(User $user, array $submissions): void
    {
        $ids = $this->events($user)->whereIn('attempts.id', $submissions)->pluck('attempts.id');
        DB::table('inbox_reads')->insertOrIgnore($ids->map(fn (string $id) => [
            'user_id' => $user->id, 'submission_id' => $id,
        ])->all());
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

        return $query->whereIn('mission.child_id', $this->families->linkedChildIds($user))
            ->where('mission.status', 'in_review')->whereNull('attempts.reviewed_at');
    }
}
