<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\FamilyRepository;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

final class FamilyProvisioning
{
    public function __construct(private readonly FamilyRepository $families) {}

    public function provision(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $family = $this->families->createFamily(['name' => $data['family'], 'timezone' => $data['timezone']]);
            $guardian = $this->families->createUser([
                'family_id' => $family->id, 'name' => $data['name'], 'role' => User::GUARDIAN,
                'relationship' => $data['relationship'], 'phone' => $data['phone'],
                'phone_hash' => Phone::fingerprint($data['phone']),
            ]);
            $this->families->audit($guardian, 'family.provisioned');
        });
    }
}
