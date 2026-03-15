<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use Carbon\Carbon;

class ScheduleSuggestionService
{
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

    public function suggestSlots(Section $section, int $durationMinutes, ?string $preferredDays = null): array
    {
        $term = $section->offering->term;
        $instructorId = $section->instructor_id;
        
        $rooms = Room::where('is_active', true)->get();
        
        // Find all existing blocks in the term
        $existingBlocks = MeetingBlock::whereHas('section.offering', function ($q) use ($term) {
            $q->where('term_id', $term->id);
        })->get();

        $suggestions = [];

        // Define search boundaries
        $startHour = 8;
        $endHour = 20;
        $intervalMinutes = 30;

        $targetDaysCombinations = $preferredDays ? [explode(',', $preferredDays)] : [['Mon', 'Wed', 'Fri'], ['Tue', 'Thu']];

        foreach ($targetDaysCombinations as $daysPattern) {
            for ($hour = $startHour; $hour < $endHour; $hour++) {
                for ($minute = 0; $minute < 60; $minute += $intervalMinutes) {
                    $startMin = ($hour * 60) + $minute;
                    $endMin = $startMin + $durationMinutes;

                    if ($endMin > ($endHour * 60)) {
                        continue;
                    }

                    $startStr = sprintf('%02d:%02d', $hour, $minute);
                    $endStr = sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60);

                    // 1. Check if the instructor is available
                    $instructorConflict = false;
                    if ($instructorId) {
                        foreach ($existingBlocks as $block) {
                            if ($block->section->instructor_id === $instructorId) {
                                if ($this->hasConflict($daysPattern, $startMin, $endMin, $block)) {
                                    $instructorConflict = true;
                                    break;
                                }
                            }
                        }
                    }

                    if ($instructorConflict) continue;

                    // 2. Find available rooms
                    $availableRooms = [];
                    foreach ($rooms as $room) {
                        $roomConflict = false;
                        foreach ($existingBlocks as $block) {
                            if ($block->room_id === $room->id) {
                                if ($this->hasConflict($daysPattern, $startMin, $endMin, $block)) {
                                    $roomConflict = true;
                                    break;
                                }
                            }
                        }
                        if (!$roomConflict) {
                            $availableRooms[] = $room;
                        }
                    }

                    if (count($availableRooms) > 0) {
                        $suggestions[] = [
                            'days' => $daysPattern,
                            'starts_at' => $startStr,
                            'ends_at' => $endStr,
                            'available_rooms' => array_slice($availableRooms, 0, 3) // suggest up to 3 rooms
                        ];
                    }

                    if (count($suggestions) >= 10) {
                        break 3; // Limit to 10 suggestions total
                    }
                }
            }
        }

        return $suggestions;
    }

    private function hasConflict(array $days, int $startMin, int $endMin, MeetingBlock $block): bool
    {
        $blockDays = $block->days_json ?? [];
        if (empty(array_intersect($days, $blockDays))) {
            return false;
        }

        $blockStartMin = $this->timeToMinutes($block->starts_at);
        $blockEndMin = $this->timeToMinutes($block->ends_at);

        // Conflict strictly: overlap means max(start1, start2) < min(end1, end2)
        return max($startMin, $blockStartMin) < min($endMin, $blockEndMin);
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return ($h * 60) + $m;
    }
}
