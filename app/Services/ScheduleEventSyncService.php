<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OpenLab;
use App\Models\OfficeHourBlock;
use App\Models\ScheduledEvent;
use App\Models\Term;

class ScheduleEventSyncService
{
    public function syncMeetingBlock(MeetingBlock $meetingBlock): ScheduledEvent
    {
        $meetingBlock->loadMissing('section.offering.catalogCourse', 'section.instructor');
        $section = $meetingBlock->section;
        $offering = $section->offering;
        $courseCode = $offering->catalogCourse?->code ?? 'Class';

        $event = $this->upsert($meetingBlock, [
            'term_id' => $offering->term_id,
            'event_type' => ScheduledEvent::TYPE_CLASS,
            'display_name' => trim($courseCode.' '.$section->section_code),
            'category' => $meetingBlock->type?->value ?? (string) $meetingBlock->type,
            'room_id' => $meetingBlock->room_id,
            'days_json' => ScheduleConflictService::normalizeDays($meetingBlock->days_json),
            'starts_at' => $meetingBlock->starts_at,
            'ends_at' => $meetingBlock->ends_at,
            'notes' => $meetingBlock->notes,
        ]);

        $event->staffAssignments()->delete();
        if ($section->instructor_id) {
            $event->staffAssignments()->create([
                'staff_type' => 'instructor',
                'staff_id' => $section->instructor_id,
                'role' => 'instructor',
            ]);
        }

        return $event;
    }

    public function syncOpenLab(OpenLab $openLab): ScheduledEvent
    {
        $event = $this->upsert($openLab, [
            'term_id' => $openLab->term_id,
            'event_type' => ScheduledEvent::TYPE_OPEN_LAB,
            'display_name' => $openLab->name,
            'category' => 'OPEN_LAB',
            'room_id' => $openLab->room_id,
            'days_json' => ScheduleConflictService::normalizeDays($openLab->days_json),
            'starts_at' => $openLab->starts_at,
            'ends_at' => $openLab->ends_at,
            'notes' => $openLab->notes,
        ]);

        $event->staffAssignments()->delete();
        $event->staffAssignments()->create([
            'staff_type' => 'lab_tech',
            'staff_id' => $openLab->lab_tech_id,
            'role' => 'lab_tech',
        ]);

        return $event;
    }

    public function syncOfficeHourBlock(OfficeHourBlock $officeHourBlock): ScheduledEvent
    {
        $officeHourBlock->loadMissing('instructor');
        $event = $this->upsert($officeHourBlock, [
            'term_id' => $officeHourBlock->term_id,
            'event_type' => ScheduledEvent::TYPE_OFFICE_HOUR,
            'display_name' => 'Office Hours'.($officeHourBlock->instructor?->name ? ': '.$officeHourBlock->instructor->name : ''),
            'category' => 'OFFICE_HOURS',
            'room_id' => null,
            'days_json' => ScheduleConflictService::normalizeDays($officeHourBlock->days_json),
            'starts_at' => $officeHourBlock->starts_at,
            'ends_at' => $officeHourBlock->ends_at,
            'notes' => $officeHourBlock->notes,
        ]);

        $event->staffAssignments()->delete();
        $event->staffAssignments()->create([
            'staff_type' => 'instructor',
            'staff_id' => $officeHourBlock->instructor_id,
            'role' => 'office_hours',
        ]);

        return $event;
    }

    public function removeForSource(object $source): void
    {
        $this->removeForSources($source::class, [$source->getKey()]);
    }

    public function removeForSources(string $sourceType, array $sourceIds): void
    {
        ScheduledEvent::query()
            ->where('source_type', $sourceType)
            ->whereIn('source_id', array_filter($sourceIds))
            ->delete();
    }

    public function rebuildTerm(Term $term): array
    {
        ScheduledEvent::query()
            ->where('term_id', $term->id)
            ->whereIn('source_type', [MeetingBlock::class, OpenLab::class, OfficeHourBlock::class])
            ->delete();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor'])
            ->whereHas('section.offering', fn ($offerings) => $offerings->where('term_id', $term->id))
            ->get();
        $openLabs = OpenLab::query()->where('term_id', $term->id)->get();
        $officeHourBlocks = OfficeHourBlock::query()->where('term_id', $term->id)->get();

        $meetingBlocks->each(fn (MeetingBlock $meetingBlock) => $this->syncMeetingBlock($meetingBlock));
        $openLabs->each(fn (OpenLab $openLab) => $this->syncOpenLab($openLab));
        $officeHourBlocks->each(fn (OfficeHourBlock $officeHourBlock) => $this->syncOfficeHourBlock($officeHourBlock));

        return [
            'meeting_blocks' => $meetingBlocks->count(),
            'open_labs' => $openLabs->count(),
            'office_hour_blocks' => $officeHourBlocks->count(),
        ];
    }

    private function upsert(object $source, array $attributes): ScheduledEvent
    {
        return ScheduledEvent::query()->updateOrCreate([
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
        ], $attributes);
    }
}
