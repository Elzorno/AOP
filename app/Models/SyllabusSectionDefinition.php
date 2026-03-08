<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyllabusSectionDefinition extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'category',
        'description',
        'default_content',
        'scope',
        'is_required',
        'is_active',
        'is_locked',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function sectionItems(): HasMany
    {
        return $this->hasMany(SyllabusSectionItem::class);
    }
}
