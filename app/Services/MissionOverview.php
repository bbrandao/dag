<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Repositories\FamilyRepository;
use App\Repositories\MissionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class MissionOverview
{
    public function __construct(
        private readonly MissionRepository $missions,
        private readonly AllowanceRepository $allowances,
        private readonly TaskCalendar $calendar,
        private readonly FamilyRepository $families,
    ) {}

    public function reviews(User $guardian, ?string $date = null, ?string $child = null, ?string $status = null): array
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $timezone = $guardian->family->timezone;
        $today = now($timezone)->toDateString();
        $date ??= $today;
        $children = $this->families->childrenForDashboard($guardian);
        $profile = $child !== null ? $children->firstWhere('id', $child) : ($children->count() === 1 ? $children->first() : null);
        if ($date === $today && ($child === null || $profile !== null)) {
            $this->calendar->materialize($guardian->family, $profile?->id);
        }
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $missions = $this->missions->reviewQueue($guardian, $date, $day->utc(), $day->addDay()->utc(), $child, $status === 'in_review')
            ->map(fn (object $mission) => $this->display($mission, $guardian))
            ->when($status !== null, fn ($missions) => $missions->where('status', $status)->values());

        return ['missions' => $missions, 'date' => $date, 'today' => $today, 'child' => $child, 'status' => $status,
            'childName' => $profile?->name];
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
                'review_version' => $attempt->review_version,
                'photo_url' => $attempt->photo_path ? route('missions.photo', [$mission, $attempt->id], false) : null,
            ]);
        $reopening = $this->missions->latestReopeningReason($mission);

        return [
            'mission' => [
                ...$this->display($occurrence, $user),
                'child_name' => $routeRole === User::GUARDIAN ? User::whereKey($occurrence->child_id)->value('name') : $user->name,
            ],
            'attempts' => $attempts,
            'reopening' => $reopening,
            'requestId' => (string) Str::uuid(),
            'isGuardian' => $routeRole === User::GUARDIAN,
            'creditSettled' => $routeRole === User::GUARDIAN && $occurrence->status === 'approved'
                && $this->allowances->creditIsSettled($mission),
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
            'submitted_at' => isset($mission->last_submitted_at)
                ? $this->localTime($mission->last_submitted_at, $user)->format('d/m/Y H:i') : null,
        ];
    }

    private function localTime(string $value, User $user): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone($user->family->timezone);
    }
}
