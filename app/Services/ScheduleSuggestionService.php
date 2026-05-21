<?php

namespace App\Services;

use App\Models\InstructorPreference;
use App\Models\MeetingBlock;
use App\Models\Room;
use App\Models\Section;

/**
 * Suggests canonical schedule blocks for a section.
 *
 * Replaces the previous brute-force 30-minute-interval search with a lookup
 * against the ScheduleBlockLibrary canonical block list, so every suggestion
 * conforms to the institution's published semester schedule blocks PDF.
 *
 * Suggestion ranking:
 *   1. Canonical blocks whose day pattern fits the course's credit/hour profile
 *   2. Filtered by instructor availability (existing meeting blocks in the term)
 *   3. Filtered by room availability (at least one room free)
 *   4. Sorted chronologically within each day pattern
 *   5. Returned in the order: preferred pattern first, then alternates
 */
class ScheduleSuggestionService
{
    /**
     * Returns up to $limit canonical slot suggestions for $section.
     *
     * @param  Section  $section
     * @param  string   $blockType      'LECTURE' | 'LAB'
     * @param  int      $limit
     * @return array    [ {days, starts_at, ends_at, credit_label, available_rooms[]} ]
     */
    public function suggestSlots(Section $section, string $blockType = 'LECTURE', int $limit = 10): array
    {
        $section->loadMissing('offering.catalogCourse', 'offering.term');

        $course = $section->offering->catalogCourse;
        $term   = $section->offering->term;

        $creditHours       = (int) round((float) ($course?->credits ?? 3));
        $lectureHrsPerWeek = (float) ($course?->lecture_hours_per_week ?? 0);
        $labHrsPerWeek     = (float) ($course?->lab_hours_per_week ?? 0);

        // If the course doesn't have a lecture/lab split, use credits as a proxy
        if (($lectureHrsPerWeek + $labHrsPerWeek) <= 0) {
            $lectureHrsPerWeek = (float) ($course?->contact_hours_per_week ?? $creditHours);
        }

        // Candidate blocks from the canonical library
        $candidates = ScheduleBlockLibrary::suggestionsFor(
            $creditHours,
            $lectureHrsPerWeek,
            $labHrsPerWeek,
            $blockType
        );

        if (empty($candidates)) {
            return [];
        }

        // Load all existing blocks in the term for conflict checking
        $existingBlocks = MeetingBlock::query()
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        // Load active rooms
        $rooms = Room::where('is_active', true)->get();

        $instructorId = $section->instructor_id;
        $buffer       = (int) ($term->buffer_minutes ?? 0);

        // Load instructor preferences for filtering/ranking
        $preferences = $instructorId
            ? InstructorPreference::where('term_id', $term->id)
                ->where('instructor_id', $instructorId)
                ->first()
            : null;

        $preferredDays    = $preferences?->preferred_days ?? [];
        $unavailableTimes = $preferences?->unavailable_times ?? [];

        $suggestions = [];

        foreach ($candidates as $candidate) {
            $days     = $candidate['days'];
            $startsAt = $candidate['starts_at'];
            $endsAt   = $candidate['ends_at'];

            // 0. Filter out slots that overlap the instructor's unavailable times
            if (!empty($unavailableTimes)) {
                $blocked = false;
                foreach ($unavailableTimes as $unavail) {
                    if (!in_array($unavail['day'], $days, true)) {
                        continue;
                    }
                    if (ScheduleConflictService::timesOverlap($startsAt, $endsAt, $unavail['starts_at'], $unavail['ends_at'], 0)) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
            }

            // 1. Check instructor availability
            if ($instructorId) {
                $conflict = false;
                foreach ($existingBlocks as $block) {
                    if ((int) $block->section->instructor_id !== (int) $instructorId) {
                        continue;
                    }
                    if (!ScheduleConflictService::dayOverlap($days, $block->days_json ?? [])) {
                        continue;
                    }
                    if (ScheduleConflictService::timesOverlap($startsAt, $endsAt, $block->starts_at, $block->ends_at, $buffer)) {
                        $conflict = true;
                        break;
                    }
                }
                if ($conflict) {
                    continue;
                }
            }

            // 2. Find available rooms
            $availableRooms = [];
            foreach ($rooms as $room) {
                $roomConflict = false;
                foreach ($existingBlocks as $block) {
                    if ((int) $block->room_id !== (int) $room->id) {
                        continue;
                    }
                    if (!ScheduleConflictService::dayOverlap($days, $block->days_json ?? [])) {
                        continue;
                    }
                    if (ScheduleConflictService::timesOverlap($startsAt, $endsAt, $block->starts_at, $block->ends_at, $buffer)) {
                        $roomConflict = true;
                        break;
                    }
                }
                if (!$roomConflict) {
                    $availableRooms[] = ['id' => $room->id, 'name' => $room->name];
                }
            }

            // Only suggest this slot if at least one room is free (or modality is online)
            $modality = $section->modality?->value ?? 'IN_PERSON';
            if ($modality !== 'ONLINE' && empty($availableRooms)) {
                continue;
            }

            // Score: count how many of this slot's days match preferred days
            $preferenceScore = !empty($preferredDays)
                ? count(array_intersect($days, $preferredDays))
                : 0;

            $suggestions[] = [
                'days'             => $days,
                'starts_at'        => $startsAt,
                'ends_at'          => $endsAt,
                'credit_label'     => $candidate['credit_label'],
                'available_rooms'  => array_slice($availableRooms, 0, 5),
                'preference_score' => $preferenceScore,
                'preferred'        => $preferenceScore > 0,
            ];
        }

        // Sort: preferred slots first, then chronological
        usort($suggestions, function ($a, $b) {
            if ($b['preference_score'] !== $a['preference_score']) {
                return $b['preference_score'] <=> $a['preference_score'];
            }
            return $a['starts_at'] <=> $b['starts_at'];
        });

        return array_slice($suggestions, 0, $limit);
    }
}
