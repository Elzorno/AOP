<?php

namespace App\Models;

use App\Services\ScheduleEventSyncService;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected static function booted(): void
    {
        static::deleting(function (self $instructor) {
            // The foreign keys null/cascade legacy rows directly, so clean the projection before they run.
            ScheduledEventStaffAssignment::query()
                ->where('staff_type', 'instructor')
                ->where('staff_id', $instructor->id)
                ->delete();

            app(ScheduleEventSyncService::class)->removeForSources(
                OfficeHourBlock::class,
                $instructor->officeHourBlocks()->pluck('id')->all(),
            );
        });
    }

    public static function normalizeColorHex(null|string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (!str_starts_with($value, '#')) {
            $value = '#'.$value;
        }

        if (!preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $value)) {
            return null;
        }

        return strtoupper($value);
    }

    protected function colorHex(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => self::normalizeColorHex(is_scalar($value) ? (string) $value : null),
        );
    }

    protected function colorHexCss(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => self::normalizeColorHex($attributes['color_hex'] ?? null),
        );
    }
}
