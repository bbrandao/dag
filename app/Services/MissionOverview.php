<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\MissionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class MissionOverview
{
    public function __construct(private readonly MissionRepository $missions) {}

    public function reviews(User $guardian): array
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $missions = $this->missions->reviewQueue($guardian)
            ->map(fn (object $mission) => $this->display($mission, $guardian));

        return ['missions' => $missions];
    }

    public function details(User $user, string $mission, string $routeRole): array
    {
        abort_unless($user->role === $routeRole, 403);
        $occurrence = $this->missions->find($user, $mission);
        $attempts = $this->missions->attempts($mission)
            ->map(fn (object $attempt) => [
                'id' => $attempt->id,
                'comment' => $attempt->comment,
                'decision' => $attempt->decision,
                'feedback' => $attempt->feedback,
                'submitted_at' => $this->localTime($attempt->submitted_at, $user)->format('d/m/Y H:i'),
                'photo_url' => $attempt->photo_path ? route('missions.photo', [$mission, $attempt->id], false) : null,
            ]);
        $reopening = $this->missions->latestReopeningReason($mission);

        return [
            'mission' => $this->display($occurrence, $user),
            'attempts' => $attempts,
            'reopening' => $reopening,
            'requestId' => (string) Str::uuid(),
            'isGuardian' => $routeRole === User::GUARDIAN,
        ];
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
