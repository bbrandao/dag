<?php

namespace App\Services;

use App\Models\Family;
use App\Models\TaskTemplate;
use App\Repositories\TaskRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TaskCalendar
{
    public function __construct(private readonly TaskRepository $tasks) {}

    public function materialize(Family $family, ?string $childId = null): void
    {
        $now = CarbonImmutable::now($family->timezone);
        $this->tasks->eachEligible($family->id, $now->toDateString(), $childId,
            function (TaskTemplate $candidate) use ($now, $childId): void {
                DB::transaction(function () use ($candidate, $now, $childId): void {
                    $task = $this->tasks->lockWithActiveChildren($candidate->id, $childId);

                    if ($task !== null) {
                        $this->materializeTask($task, $now);
                    }
                });
            });
    }

    public function materializeTask(TaskTemplate $task, CarbonImmutable $now): void
    {
        $date = $now->startOfDay();
        $period = match ($task->frequency) {
            'daily' => $date,
            'weekly' => $date->startOfWeek(CarbonInterface::MONDAY),
            'monthly' => $date->startOfMonth(),
            'yearly' => $date->startOfYear(),
        };
        $schedule = $task->schedule;
        $day = match ($task->frequency) {
            'daily' => in_array($date->dayOfWeekIso, $schedule['days'], true) ? $date : null,
            'weekly' => $period->addDays($schedule['day'] - 1),
            'monthly' => $this->monthDay($period, $schedule['day']),
            'yearly' => $this->monthDay($period->month($schedule['month']), $schedule['day']),
        };

        if ($day === null) {
            return;
        }

        $startsOn = CarbonImmutable::parse($task->starts_on, $now->timezone)->startOfDay();
        $createdAt = $task->created_at->toImmutable()->setTimezone($now->timezone);
        $opensAt = max($period, $startsOn, $createdAt);
        $time = $day->dayOfWeekIso >= 6 ? $task->weekend_time : $task->weekday_time;
        $dueAt = $day->setTimeFromTimeString($time);

        if ($opensAt->gt($now) || $day->lt($opensAt->startOfDay())) {
            return;
        }

        $previousCadence = $this->tasks->previousCadenceChildIds($task, $date->toDateString());

        $rows = $task->children->reject(fn ($child) => $previousCadence->contains($child->id))->map(fn ($child) => [
            'id' => (string) Str::uuid(),
            'family_id' => $task->family_id,
            'task_id' => $task->id,
            'child_id' => $child->id,
            'period_start' => $period->toDateString(),
            'due_on' => $day->toDateString(),
            'opens_at' => $opensAt->utc(),
            'due_at' => $dueAt->utc(),
            'title' => $task->title,
            'criteria' => $task->criteria,
            'points' => $task->points,
            'frequency' => $task->frequency,
            'photo_mode' => $task->photo_mode,
        ])->all();

        $this->tasks->insertOccurrences($rows);
    }

    private function monthDay(CarbonImmutable $month, ?int $day): CarbonImmutable
    {
        return $month->day(min($day ?? $month->daysInMonth, $month->daysInMonth));
    }
}
