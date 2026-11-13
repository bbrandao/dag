<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTaskRequest;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TaskController
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Tasks', $this->tasks->dashboard($request->user()));
    }

    public function store(SaveTaskRequest $request): RedirectResponse
    {
        $this->tasks->save($request->user(), $request->validated());

        return redirect()->route($request->query('from') === 'new' ? 'guardian.tasks.create' : 'guardian.tasks');
    }

    public function create(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('NewTask', $this->tasks->formData($request->user()));
    }

    public function update(SaveTaskRequest $request, string $task): RedirectResponse
    {
        $this->tasks->save($request->user(), $request->validated(), $task);

        return $request->query('from') === 'edit'
            ? redirect()->route('guardian.tasks.edit', $task)
            : redirect()->route('guardian.tasks');
    }

    public function edit(Request $request, string $task): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('NewTask', $this->tasks->editData($request->user(), $task));
    }

    public function destroy(Request $request, string $task): RedirectResponse|JsonResponse
    {
        $this->tasks->delete($request->user(), $task);

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('guardian.tasks');
    }

    public function missions(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Missions', $this->tasks->missions($request->user(), $request->query('frequency')));
    }
}
