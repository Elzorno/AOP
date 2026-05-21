<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleChangeLog extends Model
{
    protected $fillable = [
        'term_id', 'section_id', 'meeting_block_id', 'user_id',
        'action', 'payload_before', 'payload_after',
    ];

    protected $casts = [
        'payload_before' => 'array',
        'payload_after'  => 'array',
    ];

    public function term(): BelongsTo { return $this->belongsTo(Term::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function meetingBlock(): BelongsTo { return $this->belongsTo(MeetingBlock::class); }
    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class); }
}
