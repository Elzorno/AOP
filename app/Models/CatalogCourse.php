<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogCourse extends Model
{
    protected $fillable = [
        'code','title','credits',
        'lecture_hours_per_week','lab_hours_per_week',
        'description','prereq_text','coreq_text',
        'is_active',
    ];

    protected $casts = [
        'credits' => 'decimal:2',
        'lecture_hours_per_week' => 'decimal:2',
        'lab_hours_per_week' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class); }
}
