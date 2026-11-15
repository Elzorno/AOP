<?php

namespace App\Models;

use App\Enums\MeetingBlockType;
use App\Services\ScheduleEventSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingBlock extends Model
{
    protected $fillable = [
        'section_id','type','days_json','starts_at','ends_at','room_id','notes',
    ];

    protected $casts = [
        'type' => MeetingBlockType::class,
        'days_json' => 'array',
    ];

    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function scheduledEvent(): BelongsTo { return $this->belongsTo(ScheduledEvent::class); }

    protected static function booted(): void
    {
        static::saved(fn (self $meetingBlock) => app(ScheduleEventSyncService::class)->syncMeetingBlock($meetingBlock));
        static::deleting(fn (self $meetingBlock) => app(ScheduleEventSyncService::class)->removeForSource($meetingBlock));
    }
}
