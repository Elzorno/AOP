<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\ScheduleChangeLog;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');

        return $term;
    }

    private function ensureScheduleUnlocked(Term $term): void
    {
        abort_if($term->schedule_locked, 403, 'Schedule is locked for the active term. Unlock it before making schedule changes.');
    }

    public function index(Request $request)
    {
        $term = $this->activeTermOrFail();

        $queryText = trim((string) $request->input('q', ''));
        $modality = (string) $request->input('modality', '');
        $instructorId = $request->integer('instructor_id');
        $missing = (string) $request->input('missing', '');
        $sort = (string) $request->input('sort', 'recent');

        $sectionsQuery = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id));

        if ($queryText !== '') {
            $sectionsQuery->where(function ($q) use ($queryText) {
                $q->where('section_code', 'like', "%{$queryText}%")
                    ->orWhereHas('offering.catalogCourse', function ($courseQ) use ($queryText) {
                        $courseQ->where('code', 'like', "%{$queryText}%")
                            ->orWhere('title', 'like', "%{$queryText}%");
                    })
                    ->orWhereHas('instructor', fn ($insQ) => $insQ->where('name', 'like', "%{$queryText}%"));
            });
        }

        $validModalities = collect(SectionModality::cases())->map(fn ($m) => $m->value)->all();
        if (in_array($modality, $validModalities, true)) {
            $sectionsQuery->where('modality', $modality);
        }

        if (!empty($instructorId)) {
            $sectionsQuery->where('instructor_id', $instructorId);
        }

        switch ($missing) {
            case 'instructor':
                $sectionsQuery->whereNull('instructor_id');
                break;
            case 'meeting_blocks':
                $sectionsQuery->doesntHave('meetingBlocks');
                break;
            case 'room':
                $sectionsQuery->whereHas('meetingBlocks', fn ($q) => $q->whereNull('room_id'));
                break;
            case 'low_enrollment':
                $sectionsQuery->whereNotNull('section_capacity')
                    ->whereRaw('enrolled_count <= FLOOR(section_capacity * 0.70)');
                break;
        }

        switch ($sort) {
            case 'course':
                $sectionsQuery
                    ->orderBy(
                        Offering::query()
                            ->join('catalog_courses', 'catalog_courses.id', '=', 'offerings.catalog_course_id')
                            ->whereColumn('offerings.id', 'sections.offering_id')
                            ->select('catalog_courses.code')
                            ->limit(1)
                    )
                    ->orderBy('section_code');
                break;
            case 'section':
                $sectionsQuery->orderBy('section_code');
                break;
            case 'instructor':
                $sectionsQuery
                    ->orderBy(
                        Instructor::query()
                            ->whereColumn('instructors.id', 'sections.instructor_id')
                            ->select('name')
                            ->limit(1)
                    )
                    ->orderBy('section_code');
                break;
            case 'recent':
            default:
                $sectionsQuery->orderByDesc('sections.id');
                break;
        }

        $sections = $sectionsQuery->get();

        return view('aop.schedule.sections.index', [
            'term' => $term,
            'sections' => $sections,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
            'filters' => [
                'q' => $queryText,
                'modality' => $modality,
                'instructor_id' => $instructorId,
                'missing' => $missing,
                'sort' => $sort,
            ],
        ]);
    }

    public function create()
    {
        $term = $this->activeTermOrFail();

        $offerings = Offering::with('catalogCourse')
            ->where('term_id', $term->id)
            ->orderBy('id', 'desc')
            ->get();

        return view('aop.schedule.sections.create', [
            'term' => $term,
            'offerings' => $offerings,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $term = $this->activeTermOrFail();
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'offering_id' => ['required', 'integer', 'exists:offerings,id'],
            'section_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('sections', 'section_code')
                    ->where(fn ($q) => $q->where('offering_id', $request->integer('offering_id'))),
            ],
            'instructor_id' => ['nullable', 'integer', 'exists:instructors,id'],
            'modality' => ['required', Rule::enum(SectionModality::class)],
            'notes' => ['nullable', 'string'],
            'section_capacity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'enrolled_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $offering = Offering::where('id', $data['offering_id'])
            ->where('term_id', $term->id)
            ->first();
        abort_if(!$offering, 400, 'Offering not in active term.');

        $section = Section::create($data);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus' => $section->id])->with('status', 'Section created.');
        }

        return redirect()->route('aop.schedule.sections.index')->with('status', 'Section created.');
    }

    public function edit(Section $section, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();
        $section->load(['offering.catalogCourse', 'instructor', 'meetingBlocks.room']);
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');

        $conflictNotes = $this->sectionConflictNotes($term, $section, $conflicts);

        $changeLogs = ScheduleChangeLog::query()
            ->with('user')
            ->where('section_id', $section->id)
            ->latest()
            ->limit(25)
            ->get();

        return view('aop.schedule.sections.edit', [
            'term' => $term,
            'section' => $section,
            'scheduleLocked' => (bool) $term->schedule_locked,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
            'rooms' => Room::where('is_active', true)->orderBy('name')->get(),
            'meetingBlockTypes' => MeetingBlockType::cases(),
            'weekDays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'conflictNotes' => $conflictNotes,
            'changeLogs' => $changeLogs,
        ]);
    }

    public function update(Request $request, Section $section)
    {
        $term = $this->activeTermOrFail();
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'section_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('sections', 'section_code')
                    ->where(fn ($q) => $q->where('offering_id', $section->offering_id))
                    ->ignore($section->id),
            ],
            'instructor_id' => ['nullable', 'integer', 'exists:instructors,id'],
            'modality' => ['required', Rule::enum(SectionModality::class)],
            'notes' => ['nullable', 'string'],
            'section_capacity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'enrolled_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $section->update($data);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus' => $section->id])->with('status', 'Section updated.');
        }

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Section updated.');
    }

    public function destroy(Request $request, Section $section)
    {
        $term = $this->activeTermOrFail();
        $this->ensureScheduleUnlocked($term);

        abort_if($section->offering->term_id !== $term->id, 403, 'Section does not belong to the active term.');

        $offeringId = $section->offering_id;
        $label = ($section->offering->catalogCourse->code ?? 'Section').' '.$section->section_code;

        // Cascade: delete meeting blocks first (with audit log entries)
        foreach ($section->meetingBlocks as $block) {
            $before = [
                'days_json' => $block->days_json,
                'starts_at' => $block->starts_at,
                'ends_at'   => $block->ends_at,
                'room_id'   => $block->room_id,
                'type'      => $block->type?->value,
            ];
            $block->delete();
            ScheduleChangeLog::create([
                'term_id'        => $term->id,
                'section_id'     => $section->id,
                'meeting_block_id' => null,
                'user_id'        => auth()->id(),
                'action'         => 'deleted',
                'payload_before' => $before,
                'payload_after'  => null,
            ]);
        }

        ScheduleChangeLog::create([
            'term_id'        => $term->id,
            'section_id'     => $section->id,
            'meeting_block_id' => null,
            'user_id'        => auth()->id(),
            'action'         => 'section_deleted',
            'payload_before' => ['section_code' => $section->section_code, 'instructor_id' => $section->instructor_id],
            'payload_after'  => null,
        ]);

        $section->delete();

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus_offering' => $offeringId])
                ->with('status', "{$label} removed.");
        }

        return redirect()->route('aop.schedule.sections.index')
            ->with('status', "{$label} removed.");
    }

    public function bulkAssign(Request $request)
    {
        $term = $this->activeTermOrFail();
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'section_ids'   => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'exists:sections,id'],
            'instructor_id' => ['nullable', 'integer', 'exists:instructors,id'],
        ]);

        $updated = Section::query()
            ->whereIn('id', $data['section_ids'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->update(['instructor_id' => $data['instructor_id'] ?: null]);

        $name = $data['instructor_id']
            ? Instructor::find($data['instructor_id'])?->name ?? 'selected instructor'
            : 'Unassigned';

        return redirect()->route('aop.schedule.sections.index')
            ->with('status', "Updated {$updated} section(s) — instructor set to {$name}.");
    }

    public function suggest(Request $request, Section $section, \App\Services\ScheduleSuggestionService $service)
    {
        $term = $this->activeTermOrFail();
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');

        $blockType = strtoupper((string) $request->input('block_type', 'LECTURE'));
        if (!in_array($blockType, ['LECTURE', 'LAB'], true)) {
            $blockType = 'LECTURE';
        }

        $suggestions = $service->suggestSlots($section, $blockType);

        return response()->json(['suggestions' => $suggestions]);
    }

    private function sectionConflictNotes(Term $term, Section $section, ScheduleConflictService $conflicts): array
    {
        $notes = [];

        foreach ($section->meetingBlocks as $meetingBlock) {
            $days = ScheduleConflictService::normalizeDays($meetingBlock->days_json ?? []);
            if (count($days) === 0) {
                continue;
            }

            $label = ScheduleConflictService::formatMeetingBlockLabel($meetingBlock);

            $roomConflicts = $conflicts->roomConflictsForMeetingBlock(
                $term,
                (int) ($meetingBlock->room_id ?? 0),
                $days,
                (string) $meetingBlock->starts_at,
                (string) $meetingBlock->ends_at,
                $meetingBlock->id,
            );
            if ($roomConflicts->count() > 0) {
                $notes[] = $label.': room conflict(s) with '.$roomConflicts->map(fn (MeetingBlock $mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            }

            if (!empty($section->instructor_id)) {
                $insConflicts = $conflicts->instructorConflictsForMeetingBlock(
                    $term,
                    (int) $section->instructor_id,
                    $days,
                    (string) $meetingBlock->starts_at,
                    (string) $meetingBlock->ends_at,
                    $meetingBlock->id,
                );

                if ($insConflicts['meeting_blocks']->count() > 0) {
                    $notes[] = $label.': instructor class conflict(s) with '.$insConflicts['meeting_blocks']->map(fn (MeetingBlock $mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
                }

                if ($insConflicts['office_hour_blocks']->count() > 0) {
                    $notes[] = $label.': instructor office-hour conflict(s) with '.$insConflicts['office_hour_blocks']->map(fn ($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
                }
            }
        }

        return $notes;
    }
}
