<?php

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['meeting_blocks', 'open_labs', 'office_hour_blocks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('scheduled_event_id')->nullable()->unique()->constrained('scheduled_events')->nullOnDelete();
            });
        }

        foreach ([
            'meeting_blocks' => MeetingBlock::class,
            'open_labs' => OpenLab::class,
            'office_hour_blocks' => OfficeHourBlock::class,
        ] as $table => $sourceType) {
            DB::table($table)->update([
                'scheduled_event_id' => DB::raw("(SELECT id FROM scheduled_events WHERE source_type = '".str_replace("'", "''", $sourceType)."' AND source_id = {$table}.id)"),
            ]);

            if (DB::table($table)->whereNull('scheduled_event_id')->exists()) {
                throw new RuntimeException("Cannot link {$table}: one or more source records have no scheduled event.");
            }
        }
    }

    public function down(): void
    {
        foreach (['meeting_blocks', 'open_labs', 'office_hour_blocks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('scheduled_event_id');
            });
        }
    }
};
