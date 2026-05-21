<?php

namespace App\Services;

use App\Enums\SectionModality;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;

class SectionCardBuilderService
{
    public function build(Section $section, Term $term): array
    {
        $section->load(['meetingBlocks.room', 'instructor', 'offering.catalogCourse']);

        $allMeetingBlocks = MeetingBlock::query()
            ->with(['section.offering', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->with('instructor')
            ->where('term_id', $term->id)
            ->get();

        $conflictMaps = $this->buildConflictMaps($term, $allMeetingBlocks, $officeBlocks);

        $roomRequired = $section->modality?->value !== SectionModality::ONLINE->value;

        $meetingCards = $section->meetingBlocks
            ->sortBy(fn ($mb) => sprintf('%s|%s|%s', $mb->starts_at, $mb->ends_at, implode(',', $mb->days_json ?? [])))
            ->values()
            ->map(fn ($mb) => [
                'model'                    => $mb,
                'missing_room'             => $roomRequired && empty($mb->room_id),
                'room_conflict_count'      => $conflictMaps['room_block_counts'][$mb->id] ?? 0,
                'instructor_conflict_count'=> $conflictMaps['instructor_block_counts'][$mb->id] ?? 0,
            ]);

        $hasMissingInstructor = empty($section->instructor_id);
        $hasMissingMeetings   = $meetingCards->isEmpty();
        $hasMissingRoom       = $roomRequired && $meetingCards->contains(fn ($mc) => $mc['missing_room']);
        $roomConflictCount    = $conflictMaps['room_section_counts'][$section->id] ?? 0;
        $instructorConflictCount = $conflictMaps['instructor_section_counts'][$section->id] ?? 0;
        $issueCount = ($hasMissingInstructor ? 1 : 0)
            + ($hasMissingMeetings ? 1 : 0)
            + ($hasMissingRoom ? 1 : 0)
            + $roomConflictCount
            + $instructorConflictCount;

        $issueBadges = [];
        if ($hasMissingInstructor) {
            $issueBadges[] = ['tone' => 'warn', 'label' => 'Instructor needed'];
        }
        if ($hasMissingMeetings) {
            $issueBadges[] = ['tone' => 'warn', 'label' => 'Meetings missing'];
        }
        if ($hasMissingRoom) {
            $issueBadges[] = ['tone' => 'warn', 'label' => 'Room needed'];
        }
        if ($roomConflictCount > 0) {
            $issueBadges[] = ['tone' => 'danger', 'label' => $roomConflictCount.' room conflict'.($roomConflictCount === 1 ? '' : 's')];
        }
        if ($instructorConflictCount > 0) {
            $issueBadges[] = ['tone' => 'danger', 'label' => $instructorConflictCount.' instructor conflict'.($instructorConflictCount === 1 ? '' : 's')];
        }
        if ($issueBadges === []) {
            $issueBadges[] = ['tone' => 'good', 'label' => 'Ready'];
        }

        return [
            'model'               => $section,
            'meeting_cards'       => $meetingCards->all(),
            'issue_badges'        => $issueBadges,
            'conflict_notes'      => $conflictMaps['section_notes'][$section->id] ?? [],
            'issue_count'         => $issueCount,
            'has_missing_meetings'=> $hasMissingMeetings,
            'has_missing_instructor' => $hasMissingInstructor,
            'has_missing_room'    => $hasMissingRoom,
            'room_conflict_count' => $roomConflictCount,
            'instructor_conflict_count' => $instructorConflictCount,
            'is_ready'            => $issueCount === 0,
        ];
    }

    private function buildConflictMaps(Term $term, $meetingBlocks, $officeBlocks): array
    {
        $roomSectionCounts       = [];
        $instructorSectionCounts = [];
        $roomBlockCounts         = [];
        $instructorBlockCounts   = [];
        $sectionNotes            = [];
        $bufferMinutes           = (int) ($term->buffer_minutes ?? 0);

        $byRoom = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');
        foreach ($byRoom as $blocks) {
            $list  = $blocks->values();
            $count = $list->count();
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, $bufferMinutes)) {
                        continue;
                    }
                    $roomSectionCounts[$a->section_id] = ($roomSectionCounts[$a->section_id] ?? 0) + 1;
                    $roomSectionCounts[$b->section_id] = ($roomSectionCounts[$b->section_id] ?? 0) + 1;
                    $roomBlockCounts[$a->id] = ($roomBlockCounts[$a->id] ?? 0) + 1;
                    $roomBlockCounts[$b->id] = ($roomBlockCounts[$b->id] ?? 0) + 1;
                    $roomName = $a->room?->name ?? 'Room';
                    $sectionNotes[$a->section_id][] = $roomName.' conflict with '.ScheduleConflictService::formatMeetingBlockLabel($b);
                    $sectionNotes[$b->section_id][] = $roomName.' conflict with '.ScheduleConflictService::formatMeetingBlockLabel($a);
                }
            }
        }

        $meetingByInstructor = $meetingBlocks
            ->filter(fn ($mb) => (bool) $mb->section?->instructor_id)
            ->groupBy(fn ($mb) => (int) $mb->section->instructor_id);
        $officeByInstructor = $officeBlocks->groupBy('instructor_id');
        $instructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all(),
        )));

        foreach ($instructorIds as $instructorId) {
            $classList  = ($meetingByInstructor[$instructorId] ?? collect())->values();
            $officeList = ($officeByInstructor[$instructorId] ?? collect())->values();

            $classCount = $classList->count();
            for ($i = 0; $i < $classCount; $i++) {
                for ($j = $i + 1; $j < $classCount; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, $bufferMinutes)) {
                        continue;
                    }
                    $instructorSectionCounts[$a->section_id] = ($instructorSectionCounts[$a->section_id] ?? 0) + 1;
                    $instructorSectionCounts[$b->section_id] = ($instructorSectionCounts[$b->section_id] ?? 0) + 1;
                    $instructorBlockCounts[$a->id] = ($instructorBlockCounts[$a->id] ?? 0) + 1;
                    $instructorBlockCounts[$b->id] = ($instructorBlockCounts[$b->id] ?? 0) + 1;
                    $sectionNotes[$a->section_id][] = 'Instructor conflict with '.ScheduleConflictService::formatMeetingBlockLabel($b);
                    $sectionNotes[$b->section_id][] = 'Instructor conflict with '.ScheduleConflictService::formatMeetingBlockLabel($a);
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($officeList as $officeBlock) {
                    if (!ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $officeBlock->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $officeBlock->starts_at, $officeBlock->ends_at, $bufferMinutes)) {
                        continue;
                    }
                    $instructorSectionCounts[$classBlock->section_id] = ($instructorSectionCounts[$classBlock->section_id] ?? 0) + 1;
                    $instructorBlockCounts[$classBlock->id] = ($instructorBlockCounts[$classBlock->id] ?? 0) + 1;
                    $sectionNotes[$classBlock->section_id][] = 'Instructor conflict with '.ScheduleConflictService::formatOfficeHourLabel($officeBlock);
                }
            }
        }

        foreach ($sectionNotes as $id => $notes) {
            $sectionNotes[$id] = array_values(array_unique($notes));
        }

        return [
            'room_section_counts'       => $roomSectionCounts,
            'instructor_section_counts' => $instructorSectionCounts,
            'room_block_counts'         => $roomBlockCounts,
            'instructor_block_counts'   => $instructorBlockCounts,
            'section_notes'             => $sectionNotes,
        ];
    }
}
