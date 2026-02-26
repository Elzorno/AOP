<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $fillable = ['name','building','room_number','is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function meetingBlocks(): HasMany { return $this->hasMany(MeetingBlock::class); }
}
