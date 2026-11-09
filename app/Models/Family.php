<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Family extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'timezone'];
}
