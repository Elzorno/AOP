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
    /**
     * Arbitrary fixed week used as the reference grid.
     * Mon Jan 5 – Sun Jan 11, 2026.  Never shown to the user as a date —
     * FullCalendar day headers are overridden to show "Monday", "Tuesday", etc.
     */
    private const DAY_MAP = [
        'Mon' => '2026-01-05',
        'Tue' => '2026-01-06',
        'Wed' => '2026-01-07',
        'Thu' => '2026-01-08',
        'Fri' => '2026-01-09',
        'Sat' => '2026-01-10',
        'Sun' => '2026-01-11',
    ];

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set.');
        return $term;
    }

    public function index()
    {
        $term  = $this->activeTermOrFail();
        $rooms = Room::where('is_active', true)->orderBy('name')->get();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $events = $this->buildEvents($meetingBlocks);

        return view('aop.schedule.calendar.index', [
            'term'       => $term,
            'rooms'      => $rooms,
            'eventsJson' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
        ]);
    }

    /**
     * Still available as a JSON endpoint for future use / manual refresh.
     */
    public function events(Request $request)
    {
        $term = $this->activeTermOrFail();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        return response()->json($this->buildEvents($meetingBlocks));
    }

    public function update(Request $request, MeetingBlockMutationService $mutationService)
    {
        $term = $this->activeTermOrFail();
        $data = $request->validate([
            'blockId'   => 'required|integer|exists:meeting_blocks,id',
            'starts_at' => 'required|date_format:H:i',
            'ends_at'   => 'required|date_format:H:i',
            'roomId'    => 'sometimes|nullable|integer|exists:rooms,id',
        ]);

        $block = MeetingBlock::findOrFail($data['blockId']);

        try {
            $attributes = [
                'starts_at' => $data['starts_at'],
                'ends_at'   => $data['ends_at'],
            ];

            if (array_key_exists('roomId', $data)) {
                $attributes['roomId'] = $data['roomId'];
            }

            $updated = $mutationService->update($term, $block, $attributes);

            return response()->json([
                'status'  => 'success',
                'message' => 'Meeting block updated.',
                'block'   => [
                    'id'        => $updated->id,
                    'starts_at' => substr((string) $updated->starts_at, 0, 5),
                    'ends_at'   => substr((string) $updated->ends_at,   0, 5),
                    'room_id'   => $updated->room_id,
                ],
            ]);
        } catch (ValidationException $e) {
            $errors       = $e->errors();
            $firstMessage = collect($errors)->flatten()->first() ?? 'Update failed.';

            return response()->json([
                'status'  => 'error',
                'message' => $firstMessage,
                'errors'  => $errors,
            ], 422);
        }
    }

    // -----------------------------------------------------------------------

    private function buildEvents($meetingBlocks): array
    {
        $events = [];

        foreach ($meetingBlocks as $mb) {
            $days = $mb->days_json ?? [];
            if (empty($days)) {
                continue;
            }

            $startStr = $mb->starts_at ? substr((string) $mb->starts_at, 0, 5) : null;
            $endStr   = $mb->ends_at   ? substr((string) $mb->ends_at,   0, 5) : null;
            if (!$startStr || !$endStr) {
                continue;
            }

            $course      = $mb->section->offering->catalogCourse;
            $instructor  = $mb->section->instructor;
            $room        = $mb->room;
            $type        = $mb->type->value;

            // Build title shown inside the FullCalendar event block
            $lines   = [];
            $lines[] = $course->code . ' ' . $mb->section->section_code . ' (' . $type . ')';
            if ($room) {
                $lines[] = $room->name;
            }
            if ($instructor) {
                $lines[] = $instructor->name;
            }
            $title = implode("\n", $lines);

            $color = $instructor?->color_hex_css ?? '#3b82f6';

            // One FullCalendar event per day — all share a groupId so they
            // move together when dragged.
            foreach ($days as $day) {
                $date = self::DAY_MAP[$day] ?? null;
                if ($date === null) {
                    continue;
                }

                $events[] = [
                    'id'              => $mb->id . '_' . $day,
                    'groupId'         => (string) $mb->id,
                    'title'           => $title,
                    'start'           => $date . 'T' . $startStr,
                    'end'             => $date . 'T' . $endStr,
                    'backgroundColor' => $color,
                    'borderColor'     => $color,
                    'extendedProps'   => [
                        'blockId'    => $mb->id,
                        'type'       => $type,
                        'course'     => $course->code,
                        'section'    => $mb->section->section_code,
                        'instructor' => $instructor?->name,
                        'room'       => $room?->name,
                    ],
                ];
            }
        }

        return $events;
    }
}
