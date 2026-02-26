<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    protected $fillable = [
        'code','name','starts_on','ends_on','is_active',
        'weeks_in_term','slot_minutes','buffer_minutes',
        'allowed_hours_json',
        'schedule_locked','schedule_locked_at','schedule_locked_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'allowed_hours_json' => 'array',
        'schedule_locked' => 'boolean',
        'schedule_locked_at' => 'datetime',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class); }
    public function officeHourBlocks(): HasMany { return $this->hasMany(OfficeHourBlock::class); }

    public function scheduleLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'schedule_locked_by_user_id');
    }

    public function isScheduleLocked(): bool
    {
        return (bool)$this->schedule_locked;
    }
}
