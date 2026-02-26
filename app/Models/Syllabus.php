<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Syllabus extends Model
{
    protected $fillable = [
        'section_id',
        'header_snapshot_json',
        'block_order_json',
    ];

    protected $casts = [
        'header_snapshot_json' => 'array',
        'block_order_json' => 'array',
    ];

    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function renders(): HasMany { return $this->hasMany(SyllabusRender::class); }
}
