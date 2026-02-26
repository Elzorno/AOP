<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    protected $fillable = [
        'code','name','starts_on','ends_on','is_active',
        'weeks_in_term','slot_minutes','buffer_minutes',
        'allowed_hours_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'allowed_hours_json' => 'array',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class); }
    public function officeHourBlocks(): HasMany { return $this->hasMany(OfficeHourBlock::class); }
}
