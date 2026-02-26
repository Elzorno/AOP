<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offering extends Model
{
    protected $fillable = [
        'term_id','catalog_course_id',
        'delivery_method','notes',
        'prereq_override','coreq_override',
        'default_syllabus_block_set_json',
    ];

    protected $casts = [
        'default_syllabus_block_set_json' => 'array',
    ];

    public function term(): BelongsTo { return $this->belongsTo(Term::class); }
    public function catalogCourse(): BelongsTo { return $this->belongsTo(CatalogCourse::class); }
    public function sections(): HasMany { return $this->hasMany(Section::class); }
}
