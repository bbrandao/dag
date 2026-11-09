<?php

namespace App\Services;

use App\Models\TaskTemplate;
use App\Models\User;
use App\Repositories\FamilyRepository;
use App\Repositories\TaskRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly FamilyRepository $families,
        private readonly TaskCalendar $calendar,
    ) {}

    public function dashboard(User $guardian): array
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $children = $this->families->childrenForDashboard($guardian);
        $tasks = $this->tasks->forGuardian($guardian, $children->pluck('id'))
            ->map(fn (TaskTemplate $task) => [
                ...$task->only(['id', 'title', 'criteria', 'points', 'frequency', 'schedule', 'starts_on', 'photo_mode']),
                'weekday_time' => substr($task->weekday_time, 0, 5),
                'weekend_time' => substr($task->weekend_time, 0, 5),
                'children' => $task->children->pluck('name'),
                'child_ids' => $task->children->pluck('id'),
                'can_manage' => $task->children->count() === $task->active_children_count,
            ]);

        return [
            'tasks' => $tasks,
            'children' => $children,
            'today' => now($guardian->family->timezone)->toDateString(),
        ];
    }

    public function save(User $guardian, array $data, ?string $taskId = null): void
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $schedule = match ($data['frequency']) {
            'daily' => ['days' => array_map('intval', $data['daily_days'])],
            'weekly' => ['day' => (int) $data['weekly_day']],
            'monthly' => ['day' => isset($data['month_day']) ? (int) $data['month_day'] : null],
            'yearly' => ['month' => (int) $data['year_month'], 'day' => (int) $data['month_day']],
        };

        if ($data['frequency'] === 'yearly' && ! checkdate($schedule['month'], $schedule['day'], 2000)) {
            throw ValidationException::withMessages(['month_day' => 'Escolha um dia que exista no mês selecionado.']);
        }

        DB::transaction(function () use ($guardian, $data, $schedule, $taskId): void {
            $task = $taskId === null ? new TaskTemplate : $this->editableTask($guardian, $taskId);
            $children = $this->families->activeChildIds($guardian, $data['child_ids']);

            if ($children->count() !== count($data['child_ids'])) {
                throw ValidationException::withMessages(['child_ids' => 'Selecione apenas filhos vinculados ao seu perfil.']);
            }

            $task = $this->tasks->save($task, [
                ...array_intersect_key($data, array_flip([
                    'title', 'criteria', 'points', 'frequency', 'starts_on',
                    'weekday_time', 'weekend_time', 'photo_mode',
                ])),
                'family_id' => $guardian->family_id,
                'schedule' => $schedule,
            ], $children);
            $this->calendar->materializeTask($task, CarbonImmutable::now($guardian->family->timezone));
        });
    }

    private function editableTask(User $guardian, string $id): TaskTemplate
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $task = $this->tasks->lockForFamily($guardian, $id);
        $children = $this->families->activeChildIds($guardian);
        $assigned = $this->tasks->assignedActiveChildIds($task);
        abort_if($assigned->isEmpty() || $assigned->diff($children)->isNotEmpty(), 404);

        return $task;
    }

    public function missions(User $child, mixed $requested): array
    {
        abort_unless($child->role === User::CHILD, 403);

        $frequency = in_array($requested, TaskTemplate::FREQUENCIES, true) ? $requested : null;
        $family = $child->family;
        $this->calendar->materialize($family, $child->id);
        $now = now();
        $missions = $this->tasks->childMissions($child, $frequency, $now)
            ->map(function ($mission) use ($family) {
                $dueAt = CarbonImmutable::parse($mission->due_at, 'UTC')->setTimezone($family->timezone);

                return [
                    ...((array) $mission),
                    'due_at' => $dueAt->toIso8601String(),
                    'due_on' => $dueAt->toDateString(),
                    'due_time' => $dueAt->format('H:i'),
                ];
            });

        return [
            'missions' => $missions,
            'frequency' => $frequency,
            'today' => $now->setTimezone($family->timezone)->toDateString(),
        ];
    }

    public function delete(User $guardian, string $id): void
    {
        DB::transaction(fn () => $this->tasks->delete($this->editableTask($guardian, $id)));
    }
}
