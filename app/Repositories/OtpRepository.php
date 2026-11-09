<?php

namespace App\Repositories;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OtpRepository
{
    public function activeUserByPhone(string $fingerprint): ?User
    {
        return User::where('phone_hash', $fingerprint)->where('active', true)->lockForUpdate()->first();
    }

    public function lockUser(string $id): ?User
    {
        return User::whereKey($id)->lockForUpdate()->first();
    }

    public function hasLiveChallengeSince(string $user, DateTimeInterface $expiresAfter): bool
    {
        return $this->challenges()->where('user_id', $user)->whereNull('consumed_at')
            ->where('expires_at', '>', $expiresAfter)->exists();
    }

    public function invalidateForUser(string $user, DateTimeInterface $now): void
    {
        $this->challenges()->where('user_id', $user)->whereNull('consumed_at')->update(['consumed_at' => $now]);
    }

    public function insert(array $attributes): void
    {
        $this->challenges()->insert($attributes);
    }

    public function userId(string $id): ?string
    {
        return $this->challenges()->where('id', $id)->value('user_id');
    }

    public function find(string $id): ?object
    {
        return $this->challenges()->where('id', $id)->first();
    }

    public function failedAttempt(string $id): void
    {
        $this->challenges()->where('id', $id)->increment('attempts');
    }

    public function consume(string $id, DateTimeInterface $now): void
    {
        $this->challenges()->where('id', $id)->update(['consumed_at' => $now]);
    }

    public function purgeExpired(): void
    {
        $this->challenges()->where('expires_at', '<=', now())->delete();
    }

    private function challenges(): Builder
    {
        return DB::table('otp_challenges');
    }
}
