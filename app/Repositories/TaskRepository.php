<?php

namespace App\Repositories;

use App\Models\TaskTemplate;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TaskRepository
{
    public function forGuardian(User $guardian, Collection $childIds): Collection
    {
        $linked = fn ($query) => $query->whereIn('users.id', $childIds);

        return TaskTemplate::where('family_id', $guardian->family_id)->whereHas('children', $linked)
            ->withCount(['children as active_children_count' => fn ($query) => $query->where('users.active', true)])
            ->with(['children' => fn ($query) => $linked($query)->select('users.id', 'users.name')])
            ->orderBy('title')->get();
    }

    public function lockForFamily(User $guardian, string $id): TaskTemplate
    {
        return TaskTemplate::where('family_id', $guardian->family_id)->whereKey($id)
            ->lockForUpdate()->firstOrFail();
    }

    public function assignedActiveChildIds(TaskTemplate $task): Collection
    {
        return $task->children()->where('users.active', true)->pluck('users.id');
    }

    public function save(TaskTemplate $task, array $attributes, Collection $childIds): TaskTemplate
    {
        $task->fill($attributes)->save();
        DB::table('task_assignments')->where('task_id', $task->id)
            ->whereNotIn('child_id', $childIds)->update(['active' => false]);
        DB::table('task_assignments')->upsert($childIds->map(fn (string $id) => [
            'family_id' => $task->family_id, 'task_id' => $task->id, 'child_id' => $id, 'active' => true,
        ])->all(), ['task_id', 'child_id'], ['active']);

        return $task->load('children');
    }

    public function delete(TaskTemplate $task): void
    {
        $task->delete();
    }

    public function eachEligible(string $familyId, string $date, ?string $childId, callable $callback): void
    {
        TaskTemplate::where('family_id', $familyId)->where('starts_on', '<=', $date)
            ->whereHas('children', $this->activeChildren($childId))->select('id')->eachById($callback);
    }

    public function lockWithActiveChildren(string $id, ?string $childId): ?TaskTemplate
    {
        $task = TaskTemplate::whereKey($id)->lockForUpdate()->first();

        return $task?->load(['children' => $this->activeChildren($childId)]);
    }

    public function previousCadenceChildIds(TaskTemplate $task, string $date): Collection
    {
        return DB::table('task_occurrences')->where('task_id', $task->id)->where('frequency', '!=', $task->frequency)
            ->where('due_on', '>=', $date)->whereIn('child_id', $task->children->modelKeys())->pluck('child_id');
    }

    public function insertOccurrences(array $rows): void
    {
        DB::table('task_occurrences')->insertOrIgnore($rows);
    }

    public function childMissions(User $child, ?string $frequency, DateTimeInterface $now): Collection
    {
        return DB::table('task_occurrences')->where('family_id', $child->family_id)->where('child_id', $child->id)
            ->where('opens_at', '<=', $now)
            ->where(fn (Builder $query) => $query->where('status', '!=', 'pending')->orWhere('due_at', '>', $now))
            ->when($frequency, fn ($query) => $query->where('frequency', $frequency))
            ->orderBy('due_at')->orderBy('id')->get(['id', 'title', 'criteria', 'points', 'frequency', 'due_at', 'status']);
    }

    private function activeChildren(?string $childId): callable
    {
        return fn ($query) => $query->where('users.active', true)
            ->when($childId !== null, fn ($children) => $children->where('users.id', $childId));
    }
}
