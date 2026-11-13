<?php

namespace App\Repositories;

use App\Models\Family;
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

        $query = TaskTemplate::where('family_id', $guardian->family_id)->whereHas('children', $linked)
            ->withCount(['children as active_children_count' => fn ($query) => $query->where('users.active', true)])
            ->with(['children' => fn ($query) => $linked($query)->select('users.id', 'users.name')]);

        return TaskOrder::templates($query, now($guardian->family->timezone))->get();
    }

    public function findForFamily(User $guardian, string $id, bool $lock = false): TaskTemplate
    {
        return TaskTemplate::where('family_id', $guardian->family_id)->whereKey($id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
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

    public function scheduledTemplates(Family $family, Collection $childIds, string $before): Collection
    {
        $assigned = fn ($query) => $query->whereIn('users.id', $childIds)->where('users.active', true);

        return TaskTemplate::where('family_id', $family->id)->where('starts_on', '<', $before)
            ->whereHas('children', $assigned)
            ->with(['children' => fn ($query) => $assigned($query)->select('users.id')])->get();
    }

    public function scheduledOccurrences(Family $family, Collection $childIds, string $from): Collection
    {
        return DB::table('task_occurrences')->where('family_id', $family->id)->whereIn('child_id', $childIds)
            ->where('due_on', '>=', $from)->get(['task_id', 'child_id', 'frequency', 'period_start', 'due_on']);
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

    public function childMissions(User $child, ?string $frequency, DateTimeInterface $now, ?string $today = null): Collection
    {
        return $this->openedMissions($child, $now)
            ->when($frequency, fn ($query) => $query->where('task_occurrences.frequency', $frequency))
            ->when($frequency === null && $today !== null, fn (Builder $query) => $this->hideEarlyMonthly($query, $today))
            ->get();
    }

    public function dashboardMissions(User $child, DateTimeInterface $now, string $today): Collection
    {
        $missions = $this->openedMissions($child, $now);
        $this->hideEarlyMonthly($missions, $today);
        $actionable = (clone $missions)->whereIn('status', ['pending', 'adjustment']);
        $open = (clone $actionable)->where('due_at', '>', $now)->limit(5)->get();
        $expired = $actionable->where('due_at', '<=', $now)->limit(5)->get();
        $reviewed = $missions->whereIn('status', ['in_review', 'approved', 'rejected'])
            ->limit(5)->get();

        return $open->concat($expired)->concat($reviewed);
    }

    private function hideEarlyMonthly(Builder $missions, string $today): Builder
    {
        return $missions->where(fn (Builder $visible) => $visible->where('task_occurrences.frequency', '!=', 'monthly')
            ->orWhere('due_on', '<=', $today)->orWhere('status', '!=', 'pending'));
    }

    private function openedMissions(User $child, DateTimeInterface $now): Builder
    {
        $query = DB::table('task_occurrences')->where('task_occurrences.family_id', $child->family_id)->where('child_id', $child->id)
            ->where('opens_at', '<=', $now)->select(['task_occurrences.id', 'task_occurrences.title', 'task_occurrences.criteria',
                'task_occurrences.points', 'task_occurrences.frequency', 'due_at', 'status']);

        return TaskOrder::occurrences($query, now($child->family->timezone));
    }

    private function activeChildren(?string $childId): callable
    {
        return fn ($query) => $query->where('users.active', true)
            ->when($childId !== null, fn ($children) => $children->where('users.id', $childId));
    }
}
