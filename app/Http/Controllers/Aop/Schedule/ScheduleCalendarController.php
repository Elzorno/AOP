<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\Room;
use App\Models\Term;
use App\Services\MeetingBlockMutationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduleCalendarController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set.');
        return $term;
    }

    public function index()
    {
        $term = $this->activeTermOrFail();
        $rooms = Room::where('is_active', true)->orderBy('name')->get();

        return view('aop.schedule.calendar.index', [
            'term' => $term,
            'rooms' => $rooms,
        ]);
    }

    public function events(Request $request)
    {
        $term = $this->activeTermOrFail();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            })
            ->get();

        $events = [];

        // For FullCalendar, we'll map days of the week to an arbitrary date week
        // let's say the week of 2026-01-05 (Monday) to 2026-01-11 (Sunday)
        $dayMap = [
            'Mon' => '2026-01-05',
            'Tue' => '2026-01-06',
            'Wed' => '2026-01-07',
            'Thu' => '2026-01-08',
            'Fri' => '2026-01-09',
            'Sat' => '2026-01-10',
            'Sun' => '2026-01-11',
        ];

        foreach ($meetingBlocks as $mb) {
            $days = $mb->days_json ?? [];
            if (empty($days)) continue;

            $course = $mb->section->offering->catalogCourse;
            $title = $course->code . ' ' . $mb->section->section_code;
            if ($mb->room) {
                $title .= ' (' . $mb->room->name . ')';
            } else {
                $title .= ' (No Room)';
            }
            if ($mb->section->instructor) {
                $title .= ' - ' . $mb->section->instructor->name;
            }

            foreach ($days as $day) {
                if (!isset($dayMap[$day])) continue;
                $date = $dayMap[$day];

                $startStr = $mb->starts_at ? substr((string)$mb->starts_at, 0, 5) : null;
                $endStr = $mb->ends_at ? substr((string)$mb->ends_at, 0, 5) : null;

                if (!$startStr || !$endStr) continue;

                $color = $mb->section->instructor?->color_hex ?? '#3788d8';

                $events[] = [
                    'id' => $mb->id . '_' . $day,
                    'groupId' => $mb->id, // Use groupId so moving one moves all
                    'title' => $title,
                    'start' => $date . 'T' . $startStr . ':00',
                    'end' => $date . 'T' . $endStr . ':00',
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'extendedProps' => [
                        'blockId' => $mb->id,
                        'roomId' => $mb->room_id,
                        'day' => $day,
                    ],
                ];
            }
        }

        return response()->json($events);
    }

    public function update(Request $request, MeetingBlockMutationService $mutationService)
    {
        $term = $this->activeTermOrFail();
        $data = $request->validate([
            'blockId' => 'required|integer|exists:meeting_blocks,id',
            'starts_at' => 'required|date_format:H:i',
            'ends_at' => 'required|date_format:H:i',
            'roomId' => 'sometimes|nullable|integer|exists:rooms,id',
        ]);

        $block = MeetingBlock::findOrFail($data['blockId']);

        try {
            $attributes = [
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
            ];

            if (array_key_exists('roomId', $data)) {
                $attributes['roomId'] = $data['roomId'];
            }

            $updated = $mutationService->update($term, $block, $attributes);

            return response()->json([
                'status' => 'success',
                'message' => 'Meeting block updated.',
                'block' => [
                    'id' => $updated->id,
                    'starts_at' => substr((string) $updated->starts_at, 0, 5),
                    'ends_at' => substr((string) $updated->ends_at, 0, 5),
                    'room_id' => $updated->room_id,
                ],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $firstMessage = collect($errors)->flatten()->first() ?? 'Update failed.';

            return response()->json([
                'status' => 'error',
                'message' => $firstMessage,
                'errors' => $errors,
            ], 422);
        }
    }
}
