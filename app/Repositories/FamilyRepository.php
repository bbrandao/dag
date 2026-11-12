<?php

namespace App\Repositories;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FamilyRepository
{
    public function lock(string $familyId): Family
    {
        return Family::whereKey($familyId)->lockForUpdate()->firstOrFail();
    }

    public function linkedChildIds(User $guardian): Builder
    {
        return DB::table('guardian_child_links')
            ->where('family_id', $guardian->family_id)->where('guardian_id', $guardian->id)
            ->where('active', true)->select('child_id');
    }

    public function childrenForDashboard(User $guardian): Collection
    {
        return $this->activeChildren($guardian)->orderBy('name')->get(['id', 'name']);
    }

    public function activeChildIds(User $guardian, ?array $selected = null): Collection
    {
        return $this->activeChildren($guardian)
            ->when($selected !== null, fn (Builder $query) => $query->whereIn('id', $selected))
            ->pluck('id');
    }

    private function activeChildren(User $guardian): Builder
    {
        return DB::table('users')->where('family_id', $guardian->family_id)
            ->where('active', true)->whereIn('id', $this->linkedChildIds($guardian));
    }

    public function linkedChild(User $guardian, string $id, bool $activeOnly = false, bool $lock = false): User
    {
        return User::whereKey($id)->where('family_id', $guardian->family_id)->where('role', User::CHILD)
            ->whereIn('id', $this->linkedChildIds($guardian))
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }

    public function createFamily(array $data): Family
    {
        return Family::create($data);
    }

    public function createUser(array $data): User
    {
        return User::create($data);
    }

    public function birthDate(User $child): string
    {
        return DB::table('children')->where('user_id', $child->id)->value('birth_date');
    }

    public function registerChild(User $guardian, User $child, string $encryptedBirthDate): void
    {
        DB::table('children')->insert([
            'user_id' => $child->id, 'family_id' => $child->family_id, 'birth_date' => $encryptedBirthDate,
        ]);
        DB::table('guardian_child_links')->insert([
            'family_id' => $guardian->family_id, 'guardian_id' => $guardian->id,
            'child_id' => $child->id, 'authorized_at' => now(),
        ]);
    }

    public function updateChild(User $child, array $attributes, string $encryptedBirthDate): void
    {
        $child->forceFill($attributes)->save();
        DB::table('children')->where('user_id', $child->id)->update(['birth_date' => $encryptedBirthDate]);
    }

    public function setNoticePreference(User $user, bool $enabled): bool
    {
        $updated = User::whereKey($user->id)->where('active', true)->where('session_version', $user->session_version)
            ->update(['whatsapp_notices' => $enabled]);

        if ($updated !== 1) {
            return false;
        }

        $user->setAttribute('whatsapp_notices', $enabled)->syncOriginalAttribute('whatsapp_notices');

        return true;
    }

    public function audit(User $subject, string $event, ?User $actor = null): void
    {
        DB::table('audit_events')->insert([
            'family_id' => $subject->family_id, 'actor_id' => $actor?->id,
            'subject_id' => $subject->id, 'event' => $event, 'recorded_at' => now(),
        ]);
    }

    public function eachFamily(callable $callback): void
    {
        Family::eachById($callback);
    }

    public function eachActiveChild(callable $callback): void
    {
        User::where('role', User::CHILD)->where('active', true)->eachById($callback);
    }
}
