<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Data\Scheduling\MeetingBlockCommand;
use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\ScheduledEvent;
use App\Models\Section;
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

    public function index(Term $term)
    {
        $rooms = Room::where('is_active', true)->orderBy('name')->get();

        $events = $this->buildEvents($this->scheduledEventsForTerm($term));

        return view('aop.schedule.calendar.index', [
            'term' => $term,
            'rooms' => $rooms,
            'eventsJson' => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
        ]);
    }

    /**
     * Still available as a JSON endpoint for future use / manual refresh.
     */
    public function events(Term $term)
    {
        return response()->json($this->buildEvents($this->scheduledEventsForTerm($term)));
    }

    public function preview(Request $request, Term $term, MeetingBlockMutationService $mutationService)
    {
        $data = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'block_id' => 'sometimes|nullable|integer|exists:meeting_blocks,id',
            'days' => 'required|array|min:1',
            'days.*' => 'string|in:Mon,Tue,Wed,Thu,Fri,Sat,Sun|distinct',
            'starts_at' => 'required|date_format:H:i',
            'ends_at' => 'required|date_format:H:i',
            'room_id' => 'sometimes|nullable|integer|exists:rooms,id',
            'type' => 'sometimes|string|in:LECTURE,LAB,OTHER',
        ]);

        $section = Section::query()->findOrFail($data['section_id']);
        $block = isset($data['block_id']) ? MeetingBlock::query()->findOrFail($data['block_id']) : null;

        try {
            $evaluation = $mutationService->preview($term, $section, MeetingBlockCommand::fromArray($data), $block);

            return response()->json([
                'valid' => true,
                'normalized' => $evaluation['normalized'],
                'warnings' => $evaluation['warnings'],
                'policy_findings' => $evaluation['policy_findings'],
                'errors' => [],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'valid' => false,
                'normalized' => null,
                'warnings' => [],
                'policy_findings' => [],
                'errors' => $exception->errors(),
            ]);
        }
    }

    public function update(Request $request, Term $term, MeetingBlockMutationService $mutationService)
    {
        $data = $request->validate([
            'blockId' => 'required|integer|exists:meeting_blocks,id',
            'starts_at' => 'required|date_format:H:i',
            'ends_at' => 'required|date_format:H:i',
            'roomId' => 'sometimes|nullable|integer|exists:rooms,id',
            'revision' => 'required|integer|min:1',
        ]);

        $block = MeetingBlock::findOrFail($data['blockId']);

        try {
            $attributes = [
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'expected_revision' => $data['revision'],
            ];

            if (array_key_exists('roomId', $data)) {
                $attributes['roomId'] = $data['roomId'];
            }

            $updated = $mutationService->update($term, $block, MeetingBlockCommand::fromArray($attributes));
            $event = ScheduledEvent::query()
                ->where('source_type', MeetingBlock::class)
                ->where('source_id', $updated->id)
                ->firstOrFail();

            return response()->json([
                'status' => 'success',
                'message' => 'Meeting block updated.',
                'block' => [
                    'id' => $updated->id,
                    'starts_at' => substr((string) $updated->starts_at, 0, 5),
                    'ends_at' => substr((string) $updated->ends_at, 0, 5),
                    'room_id' => $updated->room_id,
                    'revision' => $event->revision,
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

    // -----------------------------------------------------------------------

    private function scheduledEventsForTerm(Term $term)
    {
        $events = ScheduledEvent::query()
            ->with(['room', 'source'])
            ->where('term_id', $term->id)
            ->get();

        $events->loadMorph('source', [
            MeetingBlock::class => ['section.offering.catalogCourse', 'section.instructor'],
            OpenLab::class => ['labTech'],
            OfficeHourBlock::class => ['instructor'],
        ]);

        return $events;
    }

    private function buildEvents($scheduledEvents): array
    {
        $events = [];

        foreach ($scheduledEvents as $scheduledEvent) {
            $days = $scheduledEvent->days_json ?? [];
            if (empty($days)) {
                continue;
            }

            $startStr = $scheduledEvent->starts_at ? substr((string) $scheduledEvent->starts_at, 0, 5) : null;
            $endStr = $scheduledEvent->ends_at ? substr((string) $scheduledEvent->ends_at, 0, 5) : null;
            if (! $startStr || ! $endStr) {
                continue;
            }

            $isOpenLab = $scheduledEvent->event_type === ScheduledEvent::TYPE_OPEN_LAB;
            $isClass = $scheduledEvent->event_type === ScheduledEvent::TYPE_CLASS;
            $source = $scheduledEvent->source;
            $instructor = $isClass ? $source?->section?->instructor : ($isOpenLab ? null : $source?->instructor);
            $staffName = $isOpenLab
                ? $source?->labTech?->name
                : ($isClass ? $source?->section?->instructor_display_name : $instructor?->name);
            $title = implode("\n", array_filter([
                ($isOpenLab ? 'Open Lab: ' : '').$scheduledEvent->display_name.($scheduledEvent->category ? ' ('.$scheduledEvent->category.')' : ''),
                $scheduledEvent->room?->name,
                $staffName ? ($isOpenLab ? 'Lab Tech: ' : '').$staffName : null,
            ]));
            $color = $isOpenLab ? '#D97706' : ($isClass ? ($instructor?->color_hex_css ?? '#3b82f6') : '#0F766E');
            foreach ($days as $day) {
                $date = self::DAY_MAP[$day] ?? null;
                if ($date === null) {
                    continue;
                }

                $events[] = [
                    'id' => 'scheduled_event_'.$scheduledEvent->id.'_'.$day,
                    'groupId' => 'scheduled_event_'.$scheduledEvent->id,
                    'title' => $title,
                    'start' => $date.'T'.$startStr,
                    'end' => $date.'T'.$endStr,
                    'backgroundColor' => $color,
                    'borderColor' => $isOpenLab ? '#B45309' : $color,
                    'editable' => $isClass,
                    'extendedProps' => [
                        'eventKind' => $scheduledEvent->event_type,
                        'blockId' => $isClass ? $source?->id : null,
                        'scheduledEventId' => $scheduledEvent->id,
                        'revision' => $scheduledEvent->revision,
                        'room' => $scheduledEvent->room?->name,
                        'staff' => $staffName,
                    ],
                ];
            }
        }

        return $events;
    }
}
