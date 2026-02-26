<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeHourBlock extends Model
{
    protected $fillable = [
        'term_id','instructor_id','days_json','starts_at','ends_at','notes',
        'is_locked','locked_at',
    ];

    protected $casts = [
        'days_json' => 'array',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    public function term(): BelongsTo { return $this->belongsTo(Term::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
}
