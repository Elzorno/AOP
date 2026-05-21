<?php

namespace App\Models;

use App\Enums\SectionModality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = [
        'offering_id','section_code','instructor_id','modality','notes',
        'section_capacity','enrolled_count',
    ];

    protected $casts = [
        'modality' => SectionModality::class,
        'section_capacity' => 'integer',
        'enrolled_count' => 'integer',
    ];

    public function offering(): BelongsTo { return $this->belongsTo(Offering::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
    public function meetingBlocks(): HasMany { return $this->hasMany(MeetingBlock::class); }
}
