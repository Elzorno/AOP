<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorPreference extends Model
{
    protected $fillable = [
        'term_id', 'instructor_id', 'max_courses', 'preferred_days', 'unavailable_times', 'notes'
    ];

    protected $casts = [
        'preferred_days' => 'array',
        'unavailable_times' => 'array',
        'max_courses' => 'integer',
    ];

    public function term(): BelongsTo { return $this->belongsTo(Term::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
}
