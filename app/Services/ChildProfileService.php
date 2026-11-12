<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\FamilyRepository;
use App\Repositories\MissionNoticeRepository;
use App\Repositories\OtpRepository;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ChildProfileService
{
    public function __construct(
        private readonly FamilyRepository $families,
        private readonly OtpRepository $challenges,
        private readonly MissionNoticeRepository $notices,
    ) {}

    public function list(User $guardian): Collection
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);
        $today = CarbonImmutable::today($guardian->family->timezone);

        return $this->families->childProfiles($guardian)->map(function (User $child) use ($today): array {
            $birthDate = Crypt::decryptString($child->birth_date);
            $birth = CarbonImmutable::parse($birthDate, $today->timezone);

            return [
                'id' => $child->id,
                'name' => $child->name,
                'birth_date' => $birthDate,
                'age' => (int) $birth->diffInYears($today),
                'phone' => $child->phone,
            ];
        });
    }
    public function register(User $guardian, array $data): void
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        DB::transaction(function () use ($guardian, $data): void {
            $child = $this->families->createUser([
                'family_id' => $guardian->family_id, 'name' => $data['name'], 'role' => User::CHILD,
                'phone' => $data['phone'], 'phone_hash' => Phone::fingerprint($data['phone']),
            ]);
            $this->families->registerChild($guardian, $child, Crypt::encryptString($data['birth_date']));
            $this->families->audit($child, 'child.registered', $guardian);
        });
    }

    public function editData(User $guardian, string $child): array
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);
        $profile = $this->families->linkedChild($guardian, $child, activeOnly: true);

        return [
            'child' => [
                'id' => $profile->id, 'name' => $profile->name, 'phone' => $profile->phone,
                'birth_date' => Crypt::decryptString($this->families->birthDate($profile)),
            ],
        ];
    }

    public function update(User $guardian, string $child, array $data): void
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        DB::transaction(function () use ($guardian, $child, $data): void {
            $profile = $this->families->linkedChild($guardian, $child, activeOnly: true, lock: true);
            $phoneChanged = $profile->phone !== $data['phone'];
            $attributes = ['name' => $data['name']];

            if ($phoneChanged) {
                $attributes += [
                    'phone' => $data['phone'], 'phone_hash' => Phone::fingerprint($data['phone']),
                    'session_version' => $profile->session_version + 1, 'remember_token' => null,
                    'whatsapp_notices' => false,
                ];
            }

            $this->families->updateChild($profile, $attributes, Crypt::encryptString($data['birth_date']));

            if ($phoneChanged) {
                $this->challenges->invalidateForUser($profile->id, now());
                $this->notices->cancelPending($profile->id);
            }

            $this->families->audit($profile, $phoneChanged ? 'child.phone_changed' : 'child.updated', $guardian);
        });
    }
}
