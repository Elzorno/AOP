<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\ScheduledEvent;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $event->staffAssignments()->create($this->staffAttributes('instructor', $section->instructor_id, 'instructor'));
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
        $event->staffAssignments()->create($this->staffAttributes('lab_tech', $openLab->lab_tech_id, 'lab_tech'));

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
        $event->staffAssignments()->create($this->staffAttributes('instructor', $officeHourBlock->instructor_id, 'office_hours'));

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

    public function reconcileTerm(Term $term): array
    {
        $sources = collect()
            ->concat(MeetingBlock::query()
                ->with(['section.offering.catalogCourse', 'section.instructor'])
                ->whereHas('section.offering', fn ($offerings) => $offerings->where('term_id', $term->id))
                ->get())
            ->concat(OpenLab::query()->where('term_id', $term->id)->get())
            ->concat(OfficeHourBlock::query()->with('instructor')->where('term_id', $term->id)->get());
        $events = ScheduledEvent::query()
            ->with('staffAssignments')
            ->where('term_id', $term->id)
            ->get();
        $eventsBySource = $events->keyBy(fn (ScheduledEvent $event) => $event->source_type.':'.$event->source_id);
        $sourceKeys = $sources->map(fn (object $source) => $source::class.':'.$source->getKey());
        $missing = 0;
        $mismatched = 0;

        foreach ($sources as $source) {
            $event = $eventsBySource->get($source::class.':'.$source->getKey());
            if (! $event) {
                $missing++;

                continue;
            }

            [$attributes, $staff] = $this->expectedProjection($source);
            $actualStaff = $event->staffAssignments
                ->map(fn ($assignment) => [$assignment->staff_type, (int) $assignment->staff_id, $assignment->role])
                ->sort()->values()->all();
            $expectedStaff = collect($staff)->sort()->values()->all();
            $different = collect($attributes)->contains(
                fn ($value, $key) => $this->comparable($event->{$key}) !== $this->comparable($value)
            );

            if ($different || $actualStaff !== $expectedStaff) {
                $mismatched++;
            }
        }

        $unmatched = $events->reject(
            fn (ScheduledEvent $event) => $sourceKeys->contains($event->source_type.':'.$event->source_id)
        )->count();

        return [
            'sources' => $sources->count(),
            'events' => $events->count(),
            'missing' => $missing,
            'mismatched' => $mismatched,
            'unmatched' => $unmatched,
        ];
    }

    private function expectedProjection(object $source): array
    {
        if ($source instanceof MeetingBlock) {
            $source->loadMissing('section.offering.catalogCourse', 'section.instructor');
            $section = $source->section;
            $offering = $section->offering;

            return [[
                'term_id' => $offering->term_id,
                'event_type' => ScheduledEvent::TYPE_CLASS,
                'display_name' => trim(($offering->catalogCourse?->code ?? 'Class').' '.$section->section_code),
                'category' => $source->type?->value ?? (string) $source->type,
                'room_id' => $source->room_id,
                'days_json' => ScheduleConflictService::normalizeDays($source->days_json),
                'starts_at' => $source->starts_at,
                'ends_at' => $source->ends_at,
                'notes' => $source->notes,
            ], $section->instructor_id ? [['instructor', (int) $section->instructor_id, 'instructor']] : []];
        }

        if ($source instanceof OpenLab) {
            return [[
                'term_id' => $source->term_id,
                'event_type' => ScheduledEvent::TYPE_OPEN_LAB,
                'display_name' => $source->name,
                'category' => 'OPEN_LAB',
                'room_id' => $source->room_id,
                'days_json' => ScheduleConflictService::normalizeDays($source->days_json),
                'starts_at' => $source->starts_at,
                'ends_at' => $source->ends_at,
                'notes' => $source->notes,
            ], [['lab_tech', (int) $source->lab_tech_id, 'lab_tech']]];
        }

        if ($source instanceof OfficeHourBlock) {
            $source->loadMissing('instructor');

            return [[
                'term_id' => $source->term_id,
                'event_type' => ScheduledEvent::TYPE_OFFICE_HOUR,
                'display_name' => 'Office Hours'.($source->instructor?->name ? ': '.$source->instructor->name : ''),
                'category' => 'OFFICE_HOURS',
                'room_id' => null,
                'days_json' => ScheduleConflictService::normalizeDays($source->days_json),
                'starts_at' => $source->starts_at,
                'ends_at' => $source->ends_at,
                'notes' => $source->notes,
            ], [['instructor', (int) $source->instructor_id, 'office_hours']]];
        }

        throw new \InvalidArgumentException('Unsupported scheduled-event source: '.$source::class);
    }

    private function comparable(mixed $value): mixed
    {
        return is_array($value) ? array_values($value) : ($value === null ? null : (string) $value);
    }

    private function staffAttributes(string $type, int $id, string $role): array
    {
        $attributes = ['staff_type' => $type, 'staff_id' => $id, 'role' => $role];
        if (Schema::hasColumn('scheduled_event_staff_assignments', 'instructor_id')) {
            $attributes['instructor_id'] = $type === 'instructor' ? $id : null;
            $attributes['lab_tech_id'] = $type === 'lab_tech' ? $id : null;
        }

        return $attributes;
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
