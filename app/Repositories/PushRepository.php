<?php

namespace App\Repositories;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PushRepository
{
    public function subscribe(User $user, string $hash, string $encrypted): void
    {
        DB::table('push_subscriptions')->upsert([[
            'user_id' => $user->id, 'session_version' => $user->session_version,
            'endpoint_hash' => $hash, 'subscription' => $encrypted,
        ]], ['endpoint_hash'], ['user_id', 'session_version', 'subscription']);
    }

    public function ownsSubscription(User $user, string $hash): bool
    {
        return DB::table('push_subscriptions')->where('endpoint_hash', $hash)
            ->where('user_id', $user->id)->where('session_version', $user->session_version)->exists();
    }

    public function unsubscribe(string $user, string $hash): void
    {
        DB::table('push_subscriptions')->where('user_id', $user)->where('endpoint_hash', $hash)->delete();
    }

    public function removeSubscription(int $id, string $encrypted): void
    {
        DB::table('push_subscriptions')->where('id', $id)->where('subscription', $encrypted)->delete();
    }

    public function guardianDevices(object $mission): Collection
    {
        return $this->activeDevices()->join('guardian_child_links as links', 'links.guardian_id', '=', 'users.id')
            ->where('links.family_id', $mission->family_id)->where('links.child_id', $mission->child_id)
            ->where('links.active', true)->where('users.family_id', $mission->family_id)
            ->where('users.role', User::GUARDIAN)->get(['devices.id', 'devices.user_id']);
    }

    public function reminders(CarbonInterface $now, CarbonInterface $until): Collection
    {
        return $this->activeDevices()->join('task_occurrences as missions', 'missions.child_id', '=', 'users.id')
            ->whereColumn('missions.family_id', 'users.family_id')->where('users.role', User::CHILD)
            ->whereIn('missions.status', ['pending', 'adjustment'])->where('missions.opens_at', '<=', $now)
            ->where('missions.due_at', '>', $now)->where('missions.due_at', '<=', $until)
            ->get(['devices.id', 'devices.user_id', 'missions.id as occurrence_id', 'missions.due_at']);
    }

    public function enqueue(array $deliveries): void
    {
        DB::table('push_deliveries')->insertOrIgnore($deliveries);
    }

    public function ready(CarbonInterface $now, ?string $submission, int $limit): Collection
    {
        return DB::table('push_deliveries')->where('status', 'pending')
            ->where('available_at', '<=', $now)->where('expires_at', '>', $now)
            ->when($submission !== null, fn (Builder $query) => $query->where('submission_id', $submission))
            ->orderBy('available_at')->orderBy('id')->limit($limit)->get();
    }

    public function claim(object $delivery, CarbonInterface $now): bool
    {
        return DB::table('push_deliveries')->where('id', $delivery->id)->where('status', 'pending')
            ->where('available_at', '<=', $now)->where('expires_at', '>', $now)
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]) === 1;
    }

    public function target(object $delivery, CarbonInterface $now): ?object
    {
        $query = $this->activeDevices()->where('devices.id', $delivery->subscription_id)
            ->where('users.id', $delivery->recipient_id)
            ->join('task_occurrences as missions', 'missions.family_id', '=', 'users.family_id')
            ->where('missions.id', $delivery->occurrence_id);

        if ($delivery->submission_id === null) {
            $query->where('users.role', User::CHILD)->whereColumn('missions.child_id', 'users.id')
                ->whereIn('missions.status', ['pending', 'adjustment'])->where('missions.opens_at', '<=', $now)
                ->where('missions.due_at', '>', $now)->where('missions.due_at', $delivery->deadline);
        } else {
            $query->where('users.role', User::GUARDIAN)->where('missions.status', 'in_review')
                ->join('guardian_child_links as links', 'links.guardian_id', '=', 'users.id')
                ->whereColumn('links.family_id', 'missions.family_id')->whereColumn('links.child_id', 'missions.child_id')
                ->where('links.active', true)
                ->join('mission_submissions as submissions', 'submissions.occurrence_id', '=', 'missions.id')
                ->where('submissions.id', $delivery->submission_id)->whereNull('submissions.reviewed_at');
        }

        return $query->first(['devices.subscription']);
    }

    public function finish(int $id, string $status, CarbonInterface $availableAt): void
    {
        DB::table('push_deliveries')->where('id', $id)->where('status', 'sending')
            ->update(['status' => $status, 'available_at' => $availableAt]);
    }

    public function prune(CarbonInterface $cutoff): void
    {
        DB::table('push_deliveries')->where('expires_at', '<', $cutoff)->delete();
    }

    private function activeDevices(): Builder
    {
        return DB::table('push_subscriptions as devices')->join('users', 'users.id', '=', 'devices.user_id')
            ->where('users.active', true)->whereColumn('users.session_version', 'devices.session_version');
    }
}
