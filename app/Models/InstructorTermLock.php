<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorTermLock extends Model
{
    protected $fillable = [
        'term_id',
        'instructor_id',
        'office_hours_locked',
        'office_hours_locked_at',
        'office_hours_locked_by_user_id',
    ];

    protected $casts = [
        'office_hours_locked' => 'boolean',
        'office_hours_locked_at' => 'datetime',
    ];

    public function term(): BelongsTo { return $this->belongsTo(Term::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
    public function lockedBy(): BelongsTo { return $this->belongsTo(User::class, 'office_hours_locked_by_user_id'); }

    public static function for(Term $term, Instructor $instructor): self
    {
        return static::firstOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            ['office_hours_locked' => false]
        );
    }
}
