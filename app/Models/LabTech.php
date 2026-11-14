<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\ScheduleEventSyncService;

class LabTech extends Model
{
    protected $table = 'lab_techs';

    protected $fillable = [
        'name',
        'email',
        'weekly_hour_limit',
        'is_active',
    ];

    protected $casts = [
        'weekly_hour_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function openLabs(): HasMany
    {
        return $this->hasMany(OpenLab::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $labTech) {
            app(ScheduleEventSyncService::class)->removeForSources(
                OpenLab::class,
                $labTech->openLabs()->pluck('id')->all(),
            );
        });
    }
}
