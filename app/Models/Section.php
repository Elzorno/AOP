<?php

namespace App\Models;

use App\Enums\SectionModality;
use App\Services\ScheduleEventSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    public const STAFF_ASSIGNMENT = 'staff';

    protected $fillable = [
        'offering_id','section_code','instructor_id','instructor_is_staff','modality','notes',
        'section_capacity','enrolled_count',
    ];

    protected $casts = [
        'modality' => SectionModality::class,
        'instructor_is_staff' => 'boolean',
        'section_capacity' => 'integer',
        'enrolled_count' => 'integer',
    ];

    public function offering(): BelongsTo { return $this->belongsTo(Offering::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
    public function meetingBlocks(): HasMany { return $this->hasMany(MeetingBlock::class); }

    public function hasInstructorAssignment(): bool
    {
        return $this->instructor_id !== null || $this->instructor_is_staff;
    }

    public function getInstructorDisplayNameAttribute(): ?string
    {
        return $this->instructor_is_staff ? 'STAFF' : $this->instructor?->name;
    }

    public function requiresMeetingBlocks(): bool
    {
        return $this->modality !== SectionModality::ONLINE;
    }

    protected static function booted(): void
    {
        static::saved(function (self $section) {
            $section->meetingBlocks()->get()->each(
                fn (MeetingBlock $meetingBlock) => app(ScheduleEventSyncService::class)->syncMeetingBlock($meetingBlock)
            );
        });

        static::deleting(function (self $section) {
            app(ScheduleEventSyncService::class)->removeForSources(
                MeetingBlock::class,
                $section->meetingBlocks()->pluck('id')->all(),
            );
        });
    }
}
