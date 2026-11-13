<?php

namespace App\Repositories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as ModelBuilder;
use Illuminate\Database\Query\Builder;

final class TaskOrder
{
    public static function templates(ModelBuilder $query, DateTimeInterface $localToday): ModelBuilder
    {
        $deadline = (int) $localToday->format('N') >= 6 ? 'weekend_time' : 'weekday_time';
        $query->orderBy($deadline);
        self::tieBreakers($query->getQuery(), 'task_templates');

        return $query;
    }

    public static function occurrences(Builder $query, DateTimeInterface $localToday): Builder
    {
        $query->join('task_templates', 'task_templates.id', '=', 'task_occurrences.task_id');
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            $query->orderByRaw('time(task_occurrences.due_at, ?)', [$localToday->format('P')]);
        } else {
            $query->orderByRaw('(task_occurrences.due_at AT TIME ZONE ?)::time', [$localToday->getTimezone()->getName()]);
        }

        self::tieBreakers($query, 'task_occurrences');

        return $query->orderBy('task_occurrences.due_at')->orderBy('task_occurrences.id');
    }

    private static function tieBreakers(Builder $query, string $source): void
    {
        $query->orderByDesc("{$source}.points")
            ->orderByRaw("CASE {$source}.frequency WHEN 'daily' THEN 1 WHEN 'weekly' THEN 2 WHEN 'monthly' THEN 3 ELSE 4 END")
            ->orderBy('task_templates.created_at')->orderBy('task_templates.id');
    }
}
