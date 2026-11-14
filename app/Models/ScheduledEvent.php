<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledEvent extends Model
{
    public const TYPE_CLASS = 'class';
    public const TYPE_OPEN_LAB = 'open_lab';
    public const TYPE_OFFICE_HOUR = 'office_hour';

    protected $fillable = [
        'term_id', 'event_type', 'source_type', 'source_id', 'display_name', 'category',
        'room_id', 'days_json', 'starts_at', 'ends_at', 'notes',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(ScheduledEventStaffAssignment::class);
    }
}
