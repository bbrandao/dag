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

    protected $hidden = ['phone', 'phone_hash'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'active' => 'boolean', 'session_version' => 'integer'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
