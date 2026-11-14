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
        $data = $this->formData($guardian);
        $children = $data['children'];
        $tasks = $this->tasks->forGuardian($guardian, $children->pluck('id'))
            ->map(fn (TaskTemplate $task) => [
                ...$this->formTask($task),
                'children' => $task->children->pluck('name'),
                'can_manage' => $task->children->count() === $task->active_children_count,
            ]);

        return ['tasks' => $tasks, ...$data];
    }

    public function editData(User $guardian, string $id): array
    {
        $task = $this->editableTask($guardian, $id, lock: false);
        $task->load(['children' => fn ($query) => $query->where('users.active', true)]);

        return [...$this->formData($guardian), 'task' => $this->formTask($task)];
    }

    private function formTask(TaskTemplate $task): array
    {
        return [
            ...$task->only(['id', 'title', 'criteria', 'points', 'frequency', 'schedule', 'starts_on', 'photo_mode']),
            'weekday_time' => substr($task->weekday_time, 0, 5),
            'weekend_time' => substr($task->weekend_time, 0, 5),
            'child_ids' => $task->children->pluck('id'),
        ];
    }

    public function formData(User $guardian): array
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        return [
            'children' => $this->families->childrenForDashboard($guardian),
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

    private function editableTask(User $guardian, string $id, bool $lock = true): TaskTemplate
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $task = $this->tasks->findForFamily($guardian, $id, $lock);
        $children = $this->families->activeChildIds($guardian);
        $assigned = $this->tasks->assignedActiveChildIds($task);
        abort_if($assigned->isEmpty() || $assigned->diff($children)->isNotEmpty(), 404);

        return $task;
    }

    public function missions(User $child, array $filters): array
    {
        abort_unless($child->role === User::CHILD, 403);

        $frequency = $filters['frequency'] ?? null;
        $status = $filters['status'] ?? null;
        $family = $child->family;
        $this->calendar->materialize($family, $child->id);
        $now = now();
        $today = $now->copy()->setTimezone($family->timezone)->toDateString();
        $date = $filters['date'] ?? $today;
        $day = CarbonImmutable::parse($date, $family->timezone)->startOfDay();
        [$start, $end] = match ($frequency) {
            'weekly', 'monthly' => [$day->startOfMonth(), $day->startOfMonth()->addMonth()],
            'yearly' => [$day->startOfYear(), $day->startOfYear()->addYear()],
            default => [$day, $day->addDay()],
        };
        $missions = $this->tasks->childMissions($child, $frequency, $now, $start->utc(), $end->utc())
            ->map(fn (object $mission) => $this->displayMission($mission, $family->timezone, $now))
            ->when($status !== null, fn ($results) => $results->where('status', $status)->values());

        return [
            'missions' => $missions,
            'frequency' => $frequency,
            'status' => $status,
            'date' => $date,
            'today' => $today,
        ];
    }

    public function dashboardMissions(User $child): array
    {
        abort_unless($child->role === User::CHILD, 403);
        $this->calendar->materialize($child->family, $child->id);
        $now = now();
        $timezone = $child->family->timezone;
        $now = $now->toImmutable();
        $day = $now->setTimezone($timezone)->startOfDay();

        return [
            'missions' => $this->tasks->dashboardMissions($child, $now, $day->addDay()->utc())
                ->map(fn (object $mission) => $this->displayMission($mission, $timezone, $now)),
            'today' => $day->toDateString(),
        ];
    }

    private function displayMission(object $mission, string $timezone, \DateTimeInterface $now): array
    {
        $due = CarbonImmutable::parse($mission->due_at, 'UTC')->setTimezone($timezone);
        $expired = in_array($mission->status, ['pending', 'adjustment'], true) && $due->lte($now);

        return [
            ...(array) $mission,
            'status' => $expired ? 'expired' : $mission->status,
            'due_at' => $due->toIso8601String(),
            'due_on' => $due->toDateString(),
            'due_time' => $due->format('H:i'),
        ];
    }

    public function delete(User $guardian, string $id): void
    {
        DB::transaction(fn () => $this->tasks->delete($this->editableTask($guardian, $id)));
    }
}
