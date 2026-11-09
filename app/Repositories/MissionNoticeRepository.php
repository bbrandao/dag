<?php

namespace App\Repositories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MissionNoticeRepository
{
    public function recipientIds(object $mission, bool $guardians): Collection
    {
        $users = User::where('family_id', $mission->family_id)->where('active', true)->where('whatsapp_notices', true);

        return ($guardians
            ? $users->where('role', User::GUARDIAN)->whereIn('id', DB::table('guardian_child_links')
                ->where('family_id', $mission->family_id)->where('child_id', $mission->child_id)->where('active', true)->select('guardian_id'))
            : $users->whereKey($mission->child_id))->pluck('id');
    }

    public function insert(array $notices): void
    {
        DB::table('mission_notices')->insertOrIgnore($notices);
    }

    public function cancelPending(string $recipient): void
    {
        DB::table('mission_notices')->where('recipient_id', $recipient)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    public function markStaleAsUnknown(DateTimeInterface $cutoff): void
    {
        DB::table('mission_notices')->where('status', 'sending')->where('available_at', '<=', $cutoff)
            ->update(['status' => 'unknown']);
    }

    public function ready(DateTimeInterface $now, ?string $submission, ?string $kind = null): Collection
    {
        return DB::table('mission_notices')->where('status', 'pending')->where('available_at', '<=', $now)
            ->when($submission, fn ($query) => $query->where('submission_id', $submission))
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->orderBy('id')->limit(10)->get();
    }

    public function claim(object $notice): bool
    {
        return DB::table('mission_notices')->where('id', $notice->id)->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->update(['status' => 'sending', 'attempts' => $notice->attempts + 1, 'available_at' => now()]) > 0;
    }

    public function update(int $id, array $attributes): void
    {
        DB::table('mission_notices')->where('id', $id)->update($attributes);
    }

    public function recipient(string $id): ?User
    {
        return User::whereKey($id)->where('active', true)->where('whatsapp_notices', true)->first();
    }

    public function mission(string $submission): object
    {
        return DB::table('mission_submissions as submissions')
            ->join('task_occurrences as missions', 'missions.id', '=', 'submissions.occurrence_id')
            ->where('submissions.id', $submission)->firstOrFail([
                'missions.id', 'missions.family_id', 'missions.child_id', 'missions.title', 'missions.due_at',
                'submissions.submitted_at', 'submissions.comment', 'submissions.photo_path', 'submissions.feedback',
            ]);
    }

    public function guardianIsLinked(string $guardian, string $child): bool
    {
        return DB::table('guardian_child_links')->where('guardian_id', $guardian)->where('child_id', $child)
            ->where('active', true)->exists();
    }
}
