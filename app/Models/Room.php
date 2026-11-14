<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\ScheduleEventSyncService;

class Room extends Model
{
    protected $fillable = ['name','building','room_number','capacity','is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity'  => 'integer',
    ];

    public function meetingBlocks(): HasMany { return $this->hasMany(MeetingBlock::class); }
    public function openLabs(): HasMany { return $this->hasMany(OpenLab::class); }
    public function scheduledEvents(): HasMany { return $this->hasMany(ScheduledEvent::class); }

    protected static function booted(): void
    {
        static::deleting(function (self $room) {
            // Open labs cascade on room deletion; remove their projections before the database cascade runs.
            app(ScheduleEventSyncService::class)->removeForSources(
                OpenLab::class,
                $room->openLabs()->pluck('id')->all(),
            );
        });
    }
}
