<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyllabusRender extends Model
{
    protected $fillable = [
        'syllabus_id','format','path','sha256','rendered_at','status','error_message',
    ];

    protected $casts = [
        'rendered_at' => 'datetime',
    ];

    public function syllabus(): BelongsTo { return $this->belongsTo(Syllabus::class); }
}
