<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\ScheduledEvent;
use App\Models\Term;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($term) {
            $meetingBlocks = MeetingBlock::query()
                ->with(['section.offering.catalogCourse', 'section.instructor'])
                ->whereHas('section.offering', fn ($offerings) => $offerings->where('term_id', $term->id))
                ->get();
            $openLabs = OpenLab::query()->where('term_id', $term->id)->get();
            $officeHourBlocks = OfficeHourBlock::query()->where('term_id', $term->id)->get();

            $expectedSources = collect([
                MeetingBlock::class => $meetingBlocks->pluck('id')->all(),
                OpenLab::class => $openLabs->pluck('id')->all(),
                OfficeHourBlock::class => $officeHourBlocks->pluck('id')->all(),
            ]);
            $unmatchedEvents = ScheduledEvent::query()
                ->where('term_id', $term->id)
                ->get()
                ->reject(fn (ScheduledEvent $event) => in_array(
                    (int) $event->source_id,
                    $expectedSources->get($event->source_type, []),
                    true,
                ))
                ->count();

            $meetingBlocks->each(fn (MeetingBlock $meetingBlock) => $this->syncMeetingBlock($meetingBlock));
            $openLabs->each(fn (OpenLab $openLab) => $this->syncOpenLab($openLab));
            $officeHourBlocks->each(fn (OfficeHourBlock $officeHourBlock) => $this->syncOfficeHourBlock($officeHourBlock));

            return [
                'meeting_blocks' => $meetingBlocks->count(),
                'open_labs' => $openLabs->count(),
                'office_hour_blocks' => $officeHourBlocks->count(),
                'unmatched_events_preserved' => $unmatchedEvents,
            ];
        }, 3);
    }

    private function upsert(object $source, array $attributes): ScheduledEvent
    {
        $identity = [
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
        ];
        $event = ScheduledEvent::query()->where($identity)->first();
        if (! $event) {
            return ScheduledEvent::query()->create([...$identity, ...$attributes, 'revision' => 1]);
        }

        $event->fill($attributes);
        $event->revision = ((int) $event->revision) + 1;
        $event->save();

        return $event;
    }
}
