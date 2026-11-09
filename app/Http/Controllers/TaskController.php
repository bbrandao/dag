<?php

namespace App\Http\Controllers;

use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\TaskCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TaskController
{
    public function index(Request $request): Response
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $children = $this->linkedChildren($guardian)->orderBy('users.name')->get(['users.id', 'users.name']);
        $linked = fn ($query) => $query->whereIn('users.id', $children->pluck('id'));
        $tasks = TaskTemplate::where('family_id', $guardian->family_id)
            ->whereHas('children', $linked)
            ->with(['children' => fn ($query) => $linked($query)->select('users.id', 'users.name')])
            ->orderBy('title')
            ->get()
            ->map(fn (TaskTemplate $task) => [
                ...$task->only(['id', 'title', 'criteria', 'points', 'frequency', 'schedule', 'starts_on', 'photo_mode']),
                'weekday_time' => substr($task->weekday_time, 0, 5),
                'weekend_time' => substr($task->weekend_time, 0, 5),
                'children' => $task->children->pluck('name'),
            ]);

        Inertia::encryptHistory();

        return Inertia::render('Tasks', [
            'tasks' => $tasks,
            'children' => $children,
            'today' => now($guardian->family->timezone)->toDateString(),
        ]);
    }

    public function store(Request $request, TaskCalendar $calendar): RedirectResponse
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'criteria' => ['required', 'string', 'max:1000'],
            'points' => ['required', 'integer', 'min:1', 'max:100000'],
            'frequency' => ['required', Rule::in(TaskTemplate::FREQUENCIES)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'weekday_time' => ['required', 'date_format:H:i'],
            'weekend_time' => ['required', 'date_format:H:i'],
            'photo_mode' => ['required', Rule::in(['disabled', 'optional', 'required'])],
            'daily_days' => ['exclude_unless:frequency,daily', 'required', 'array', 'min:1', 'max:7'],
            'daily_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'weekly_day' => ['exclude_unless:frequency,weekly', 'required', 'integer', 'between:1,7'],
            'month_day' => ['exclude_unless:frequency,monthly,yearly', 'required_if:frequency,yearly', 'nullable', 'integer', 'between:1,31'],
            'year_month' => ['exclude_unless:frequency,yearly', 'required', 'integer', 'between:1,12'],
            'child_ids' => ['required', 'array', 'min:1'],
            'child_ids.*' => ['required', 'uuid', 'distinct'],
        ], [
            'required' => 'Preencha este campo.',
            'required_if' => 'Preencha este campo.',
            'child_ids.required' => 'Selecione pelo menos um filho.',
            'daily_days.required' => 'Selecione os dias em que a missão aparece.',
            'date_format' => 'Use uma data ou horário válido.',
            'integer' => 'Informe um número inteiro.',
            'min' => 'Informe um valor dentro do intervalo indicado.',
            'max' => 'O valor ultrapassa o limite deste campo.',
            'between' => 'Informe um valor dentro do intervalo indicado.',
        ]);

        $schedule = match ($data['frequency']) {
            'daily' => ['days' => array_map('intval', $data['daily_days'])],
            'weekly' => ['day' => (int) $data['weekly_day']],
            'monthly' => ['day' => isset($data['month_day']) ? (int) $data['month_day'] : null],
            'yearly' => ['month' => (int) $data['year_month'], 'day' => (int) $data['month_day']],
        };

        if ($data['frequency'] === 'yearly' && ! checkdate($schedule['month'], $schedule['day'], 2000)) {
            throw ValidationException::withMessages(['month_day' => 'Escolha um dia que exista no mês selecionado.']);
        }

        DB::transaction(function () use ($guardian, $data, $schedule, $calendar): void {
            $children = $this->linkedChildren($guardian)
                ->whereIn('users.id', $data['child_ids'])
                ->pluck('users.id');

            if ($children->count() !== count($data['child_ids'])) {
                throw ValidationException::withMessages(['child_ids' => 'Selecione apenas filhos vinculados ao seu perfil.']);
            }

            $task = TaskTemplate::create([
                ...array_intersect_key($data, array_flip([
                    'title', 'criteria', 'points', 'frequency', 'starts_on',
                    'weekday_time', 'weekend_time', 'photo_mode',
                ])),
                'family_id' => $guardian->family_id,
                'schedule' => $schedule,
            ]);
            $task->children()->attach($children->all(), ['family_id' => $guardian->family_id]);
            $calendar->materializeTask($task, CarbonImmutable::now($guardian->family->timezone));
        });

        return redirect()->route('guardian.tasks');
    }

    public function missions(Request $request, TaskCalendar $calendar): Response
    {
        $child = $request->user();
        abort_unless($child->role === User::CHILD, 403);

        $requested = $request->query('frequency');
        $frequency = in_array($requested, TaskTemplate::FREQUENCIES, true) ? $requested : null;
        $family = $child->family;
        $calendar->materialize($family, $child->id);
        $now = now();
        $missions = DB::table('task_occurrences')
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->where('status', 'pending')
            ->where('opens_at', '<=', $now)
            ->where('due_at', '>', $now)
            ->when($frequency, fn ($query) => $query->where('frequency', $frequency))
            ->orderBy('due_at')
            ->orderBy('id')
            ->get(['id', 'title', 'criteria', 'points', 'frequency', 'due_at'])
            ->map(function ($mission) use ($family) {
                $dueAt = CarbonImmutable::parse($mission->due_at, 'UTC')->setTimezone($family->timezone);

                return [
                    ...((array) $mission),
                    'due_at' => $dueAt->toIso8601String(),
                    'due_on' => $dueAt->toDateString(),
                    'due_time' => $dueAt->format('H:i'),
                ];
            });

        Inertia::encryptHistory();

        return Inertia::render('Missions', [
            'missions' => $missions,
            'frequency' => $frequency,
            'today' => $now->setTimezone($family->timezone)->toDateString(),
        ]);
    }

    private function linkedChildren(User $guardian): Builder
    {
        return DB::table('guardian_child_links as links')
            ->join('users', 'users.id', '=', 'links.child_id')
            ->where('links.family_id', $guardian->family_id)
            ->where('links.guardian_id', $guardian->id)
            ->where('links.active', true)
            ->where('users.active', true);
    }
}
