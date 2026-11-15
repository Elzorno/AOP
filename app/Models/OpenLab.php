<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\ScheduleSourceWriteGuard;

class OpenLab extends Model
{
    protected $fillable = [
        'term_id',
        'name',
        'room_id',
        'lab_tech_id',
        'days_json',
        'starts_at',
        'ends_at',
        'notes',
    ];

    protected $casts = [
        'days_json' => 'array',
    ];

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function labTech(): BelongsTo
    {
        return $this->belongsTo(LabTech::class);
    }

    public function scheduledEvent(): BelongsTo
    {
        return $this->belongsTo(ScheduledEvent::class);
    }

    protected static function booted(): void
    {
        static::saving(fn () => ScheduleSourceWriteGuard::assertExplicitMutation());
        static::deleting(fn () => ScheduleSourceWriteGuard::assertExplicitMutation());
    }
}
