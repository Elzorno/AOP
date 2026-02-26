<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogCourse extends Model
{
    protected $fillable = [
        'code','title','department',
        'credits','credits_text','credits_min','credits_max',
        'lecture_hours_per_week','lab_hours_per_week','contact_hours_per_week',
        'course_lab_fee',
        'description','objectives','required_materials',
        'prereq_text','coreq_text','notes',
        'is_active',
    ];

    protected $casts = [
        'credits' => 'decimal:2',
        'credits_min' => 'decimal:2',
        'credits_max' => 'decimal:2',
        'lecture_hours_per_week' => 'decimal:2',
        'lab_hours_per_week' => 'decimal:2',
        'contact_hours_per_week' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class); }
}
