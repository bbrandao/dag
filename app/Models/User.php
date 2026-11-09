<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUuids;

    public const GUARDIAN = 'guardian';

    public const CHILD = 'child';

    public const RELATIONSHIPS = ['pai', 'mãe', 'padrasto', 'madrasta', 'avô', 'avó', 'responsável'];

    protected $fillable = ['family_id', 'name', 'role', 'relationship', 'phone', 'phone_hash'];

    protected $hidden = ['phone', 'phone_hash', 'remember_token'];

    public function getAuthPassword(): ?string
    {
        // The session guard also uses this fingerprint to validate remember cookies.
        $token = $this->getRememberToken();

        return $token ? hash_hmac('sha256', (string) $this->session_version, $token) : null;
    }

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'active' => 'boolean', 'session_version' => 'integer'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
