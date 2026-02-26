<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulePublication extends Model
{
    protected $fillable = [
        'term_id',
        'version',
        'notes',
        'published_at',
        'published_by_user_id',
        'storage_base_path',
        'public_token',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
