<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Data\Scheduling\MeetingBlockCommand;
use App\Data\Scheduling\OpenLabCommand;
use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\LabTech;
use App\Models\MeetingBlock;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use App\Services\MeetingBlockMutationService;
use App\Services\OpenLabMutationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduledEventController extends Controller
{
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function index(Term $term)
    {
        return view('aop.schedule.events.index', [
            'term' => $term,
            'scheduleLocked' => false,
            'events' => ScheduledEvent::query()->with(['room', 'source'])->where('term_id', $term->id)->orderBy('starts_at')->orderBy('display_name')->get(),
        ]);
    }

    public function create(Term $term)
    {
        return view('aop.schedule.events.create', [
            'term' => $term,
            'scheduleLocked' => false,
            'sections' => Section::query()->with(['offering.catalogCourse', 'instructor'])->whereHas('offering', fn ($offerings) => $offerings->where('term_id', $term->id))->orderBy('section_code')->get(),
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'labTechs' => LabTech::query()->where('is_active', true)->orderBy('name')->get(),
            'weekDays' => self::DAYS,
            'meetingTypes' => MeetingBlockType::cases(),
        ]);
    }

    public function store(Request $request, Term $term, MeetingBlockMutationService $meetingBlocks, OpenLabMutationService $openLabs)
    {
        $data = $request->validate([
            'event_type' => ['required', Rule::in([ScheduledEvent::TYPE_CLASS, ScheduledEvent::TYPE_OPEN_LAB])],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
            'name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(MeetingBlockType::class)],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')],
            'lab_tech_id' => ['nullable', 'integer', Rule::exists('lab_techs', 'id')],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(self::DAYS), 'distinct'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'End time must be after start time.']);
        }

        $days = array_values(array_unique($data['days']));
        $event = $data['event_type'] === ScheduledEvent::TYPE_CLASS
            ? $this->createClass($term, $data, $days, $meetingBlocks)
            : $this->createOpenLab($term, $data, $days, $openLabs);

        return redirect()->route('aop.schedule.events.index', $term)->with('status', $event->event_type === ScheduledEvent::TYPE_CLASS ? 'Class event added.' : 'Open lab event added.');
    }

    private function createClass(Term $term, array $data, array $days, MeetingBlockMutationService $mutations): ScheduledEvent
    {
        if (empty($data['section_id'])) {
            throw ValidationException::withMessages(['section_id' => 'A section is required for a class event.']);
        }

        $section = Section::query()->with(['offering', 'instructor'])->findOrFail($data['section_id']);
        if ((int) $section->offering->term_id !== (int) $term->id) {
            throw ValidationException::withMessages(['section_id' => 'The selected section is not in this term.']);
        }

        $source = $mutations->create($term, $section, MeetingBlockCommand::fromArray([
            'type' => $data['category'] ?? MeetingBlockType::LECTURE->value,
            'days' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]));

        return ScheduledEvent::query()->where('source_type', MeetingBlock::class)->where('source_id', $source->id)->firstOrFail();
    }

    private function createOpenLab(Term $term, array $data, array $days, OpenLabMutationService $mutations): ScheduledEvent
    {
        if (empty($data['name']) || empty($data['room_id']) || empty($data['lab_tech_id'])) {
            throw ValidationException::withMessages(['event_type' => 'Open labs require a name, room, and lab tech.']);
        }

        $source = $mutations->create($term, OpenLabCommand::fromArray([
            'name' => $data['name'],
            'room_id' => $data['room_id'],
            'lab_tech_id' => $data['lab_tech_id'],
            'days' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]));

        return ScheduledEvent::query()->where('source_type', OpenLab::class)->where('source_id', $source->id)->firstOrFail();
    }
}
