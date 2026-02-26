<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyllabusBlock extends Model
{
    protected $fillable = [
        'title','category','content_html','is_locked','version',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
    ];
}
