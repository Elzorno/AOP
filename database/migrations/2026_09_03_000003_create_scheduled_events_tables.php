<?php

use App\Models\MeetingBlock;
use App\Models\OpenLab;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('display_name');
            $table->string('category')->nullable();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->json('days_json');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['term_id', 'room_id']);
            $table->index(['term_id', 'event_type']);
        });

        Schema::create('scheduled_event_staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_event_id')->constrained()->cascadeOnDelete();
            $table->string('staff_type');
            $table->unsignedBigInteger('staff_id');
            $table->string('role');
            $table->timestamps();

            $table->unique(['scheduled_event_id', 'staff_type', 'staff_id', 'role'], 'schedule_event_staff_unique');
            $table->index(['staff_type', 'staff_id']);
        });

        // Backfill the legacy records so current terms are immediately visible to the new read model.
        MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor'])
            ->each(fn (MeetingBlock $meetingBlock) => app(\App\Services\ScheduleEventSyncService::class)->syncMeetingBlock($meetingBlock));

        OpenLab::query()
            ->with(['room', 'labTech'])
            ->each(fn (OpenLab $openLab) => app(\App\Services\ScheduleEventSyncService::class)->syncOpenLab($openLab));
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_event_staff_assignments');
        Schema::dropIfExists('scheduled_events');
    }
};
