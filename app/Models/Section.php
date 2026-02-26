<?php

namespace App\Models;

use App\Enums\SectionModality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Section extends Model
{
    protected $fillable = [
        'offering_id','section_code','instructor_id','modality','notes',
    ];

    protected $casts = [
        'modality' => SectionModality::class,
    ];

    public function offering(): BelongsTo { return $this->belongsTo(Offering::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
    public function meetingBlocks(): HasMany { return $this->hasMany(MeetingBlock::class); }
    public function syllabus(): HasOne { return $this->hasOne(Syllabus::class); }
}
