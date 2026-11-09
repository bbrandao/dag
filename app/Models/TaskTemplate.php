<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaskTemplate extends Model
{
    use HasUuids;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'family_id', 'title', 'criteria', 'points', 'frequency', 'schedule',
        'starts_on', 'weekday_time', 'weekend_time', 'photo_mode',
    ];

    protected function casts(): array
    {
        return ['schedule' => 'array', 'points' => 'integer'];
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignments', 'task_id', 'child_id');
    }
}
