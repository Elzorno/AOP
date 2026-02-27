<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyllabusRender extends Model
{
    protected $fillable = [
        'term_id',
        'section_id',
        'format',
        'status',
        'storage_path',
        'file_size',
        'sha1',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
