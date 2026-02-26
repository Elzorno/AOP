<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instructor extends Model
{
    protected $fillable = ['name','email','is_full_time','color_hex','is_active'];

    protected $casts = [
        'is_full_time' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function sections(): HasMany { return $this->hasMany(Section::class); }
    public function officeHourBlocks(): HasMany { return $this->hasMany(OfficeHourBlock::class); }
}
