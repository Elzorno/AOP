<?php

use App\Models\OfficeHourBlock;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        OfficeHourBlock::query()
            ->with('instructor')
            ->each(fn (OfficeHourBlock $officeHourBlock) => app(\App\Services\ScheduleEventSyncService::class)->syncOfficeHourBlock($officeHourBlock));
    }

    public function down(): void
    {
        \App\Models\ScheduledEvent::query()
            ->where('source_type', OfficeHourBlock::class)
            ->delete();
    }
};
