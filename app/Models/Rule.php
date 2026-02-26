<?php

namespace App\Models;

use App\Enums\RuleSeverity;
use Illuminate\Database\Eloquent\Model;

class Rule extends Model
{
    protected $fillable = [
        'key','name','severity','scope','is_enabled','config_json',
    ];

    protected $casts = [
        'severity' => RuleSeverity::class,
        'is_enabled' => 'boolean',
        'config_json' => 'array',
    ];
}
