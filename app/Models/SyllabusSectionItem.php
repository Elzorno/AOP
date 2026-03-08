<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyllabusSectionItem extends Model
{
    protected $fillable = [
        'syllabus_id',
        'syllabus_section_definition_id',
        'title_override',
        'content_markdown',
        'is_enabled',
        'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(Syllabus::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SyllabusSectionDefinition::class, 'syllabus_section_definition_id');
    }
}
