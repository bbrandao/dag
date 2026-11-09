<?php

namespace App\Services;

use App\Models\Family;
use App\Models\TaskTemplate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TaskCalendar
{
    public function materialize(Family $family, ?string $childId = null): void
    {
        $now = CarbonImmutable::now($family->timezone);
        $tasks = TaskTemplate::where('family_id', $family->id)
            ->where('starts_on', '<=', $now->toDateString());

        $activeChildren = fn ($query) => $query->where('users.active', true)
            ->when($childId !== null, fn ($children) => $children->where('users.id', $childId));

        $tasks->whereHas('children', $activeChildren)->select('id')
            ->eachById(function (TaskTemplate $candidate) use ($now, $activeChildren): void {
                DB::transaction(function () use ($candidate, $now, $activeChildren): void {
                    $task = TaskTemplate::whereKey($candidate->id)->lockForUpdate()->first();

                    if ($task !== null) {
                        $this->materializeTask($task->load(['children' => $activeChildren]), $now);
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

        if ($opensAt->gt($now) || $dueAt->lte($now)) {
            return;
        }

        $previousCadence = DB::table('task_occurrences')
            ->where('task_id', $task->id)
            ->where('frequency', '!=', $task->frequency)
            ->where('due_on', '>=', $date->toDateString())
            ->whereIn('child_id', $task->children->modelKeys())
            ->pluck('child_id');

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

        DB::table('task_occurrences')->insertOrIgnore($rows);
    }

    private function monthDay(CarbonImmutable $month, ?int $day): CarbonImmutable
    {
        return $month->day(min($day ?? $month->daysInMonth, $month->daysInMonth));
    }
}
