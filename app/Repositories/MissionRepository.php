<?php

namespace App\Repositories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MissionRepository
{
    public function __construct(private readonly FamilyRepository $families) {}

    public function find(User $viewer, string $id, bool $lock = false): object
    {
        return $this->accessible($viewer)->where('id', $id)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->firstOrFail();
    }

    public function reviewQueue(User $guardian, ?string $day = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): Collection
    {
        return $this->accessible($guardian)
            ->join('users as children', 'children.id', '=', 'task_occurrences.child_id')
            ->leftJoin('mission_submissions as pending_attempt', fn ($join) => $join
                ->on('pending_attempt.occurrence_id', '=', 'task_occurrences.id')->whereNull('pending_attempt.reviewed_at'))
            ->where('children.active', true)
            ->when($day !== null, fn (Builder $query) => $query
                ->where(fn (Builder $date) => $date->where('due_on', $day)->orWhere(fn (Builder $due) => $due
                    ->where('due_at', '>=', $start)->where('due_at', '<', $end))),
                fn (Builder $query) => $query
                    ->where(fn (Builder $queue) => $queue->where('status', 'in_review')->orWhere(fn (Builder $expired) => $expired
                        ->whereIn('status', ['pending', 'adjustment'])->where('due_at', '<=', now())))
                    ->orderByRaw("CASE WHEN status = 'in_review' THEN 0 ELSE 1 END")
                    ->orderBy('pending_attempt.submitted_at'))
            ->orderBy('due_at')->orderBy('task_occurrences.id')
            ->select('task_occurrences.id', 'title', 'status', 'due_at', 'children.name as child_name')
            ->addSelect(['last_submitted_at' => DB::table('mission_submissions')
                ->whereColumn('occurrence_id', 'task_occurrences.id')->latest('submitted_at')->limit(1)->select('submitted_at')])
            ->get();
    }

    public function attempts(string $mission): Collection
    {
        return $this->submissions($mission)
            ->orderByRaw('CASE WHEN reviewed_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('submitted_at')->orderByDesc('id')
            ->get(['id', 'comment', 'decision', 'feedback', 'photo_path', 'submitted_at', 'review_version']);
    }

    public function latestSubmissionId(string $mission): ?string
    {
        return $this->submissions($mission)->latest('submitted_at')->latest('id')->value('id');
    }

    public function archiveReview(object $attempt): void
    {
        DB::table('mission_review_changes')->insert([
            'submission_id' => $attempt->id, 'review_version' => $attempt->review_version,
            'decision' => $attempt->decision, 'feedback' => $attempt->feedback,
            'reviewer_id' => $attempt->reviewer_id, 'reviewed_at' => $attempt->reviewed_at,
        ]);
    }

    public function requestSubmissionId(string $mission, string $request): ?string
    {
        return $this->submissions($mission)->where('request_id', $request)->value('id');
    }

    public function submission(string $mission, string $id): object
    {
        return $this->submissions($mission)->where('id', $id)->firstOrFail();
    }

    public function insertSubmission(array $attributes): void
    {
        DB::table('mission_submissions')->insert($attributes);
    }

    public function updateSubmission(string $mission, string $id, array $attributes): void
    {
        $this->submissions($mission)->where('id', $id)->update($attributes);
    }

    public function updateOccurrence(string $id, array $attributes): void
    {
        DB::table('task_occurrences')->where('id', $id)->update($attributes);
    }

    public function latestReopeningReason(string $mission): ?string
    {
        return DB::table('mission_reopenings')->where('occurrence_id', $mission)->latest('id')->value('reason');
    }

    public function recordReopening(array $attributes): void
    {
        DB::table('mission_reopenings')->insert($attributes);
    }

    public function photoPath(string $mission, string $submission): ?string
    {
        return $this->submissions($mission)->where('id', $submission)->value('photo_path');
    }

    public function eachExpiredPhoto(callable $callback): void
    {
        DB::table('mission_submissions')->whereNotNull('photo_path')
            ->where('reviewed_at', '<=', now()->subDays(90))->eachById($callback);
    }

    public function clearPhoto(string $submission): void
    {
        DB::table('mission_submissions')->where('id', $submission)->update(['photo_path' => null]);
    }

    private function accessible(User $user): Builder
    {
        $query = DB::table('task_occurrences')->where('task_occurrences.family_id', $user->family_id);

        if ($user->role === User::CHILD) {
            return $query->where('task_occurrences.child_id', $user->id);
        }

        abort_unless($user->role === User::GUARDIAN, 403);

        return $query->whereIn('task_occurrences.child_id', $this->families->linkedChildIds($user));
    }

    private function submissions(string $mission): Builder
    {
        return DB::table('mission_submissions')->where('occurrence_id', $mission);
    }
}
