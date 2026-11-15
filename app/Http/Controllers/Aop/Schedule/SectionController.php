<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\ScheduleChangeLog;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use App\Services\ScheduleEventReadAdapter;
use App\Services\ScheduleSuggestionService;
use App\Services\SectionInstructorAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    public function index(Request $request, Term $term)
    {
        $queryText = trim((string) $request->input('q', ''));
        $modality = (string) $request->input('modality', '');
        $instructorId = trim((string) $request->input('instructor_id', ''));
        $missing = (string) $request->input('missing', '');
        $sort = (string) $request->input('sort', 'recent');

        $sectionsQuery = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id));

        if ($queryText !== '') {
            $matchesStaffLabel = str_contains(strtolower('STAFF'), strtolower($queryText));

            $sectionsQuery->where(function ($q) use ($queryText, $matchesStaffLabel) {
                $q->where('section_code', 'like', "%{$queryText}%")
                    ->orWhereHas('offering.catalogCourse', function ($courseQ) use ($queryText) {
                        $courseQ->where('code', 'like', "%{$queryText}%")
                            ->orWhere('title', 'like', "%{$queryText}%");
                    })
                    ->orWhereHas('instructor', fn ($insQ) => $insQ->where('name', 'like', "%{$queryText}%"));

                if ($matchesStaffLabel) {
                    $q->orWhere('instructor_is_staff', true);
                }
            });
        }

        $validModalities = collect(SectionModality::cases())->map(fn ($m) => $m->value)->all();
        if (in_array($modality, $validModalities, true)) {
            $sectionsQuery->where('modality', $modality);
        }

        if ($instructorId === Section::STAFF_ASSIGNMENT) {
            $sectionsQuery->where('instructor_is_staff', true);
        } elseif (ctype_digit($instructorId) && (int) $instructorId > 0) {
            $sectionsQuery->where('instructor_id', (int) $instructorId);
        }

        switch ($missing) {
            case 'instructor':
                $sectionsQuery->whereNull('instructor_id')->where('instructor_is_staff', false);
                break;
            case 'meeting_blocks':
                $sectionsQuery
                    ->where('modality', '!=', SectionModality::ONLINE->value)
                    ->doesntHave('meetingBlocks');
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

    public function create(Term $term)
    {
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

    public function store(Request $request, Term $term, SectionInstructorAssignmentService $sections)
    {
        $data = $request->validate([
            'offering_id' => ['required', 'integer', 'exists:offerings,id'],
            'section_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('sections', 'section_code')
                    ->where(fn ($q) => $q->where('offering_id', $request->integer('offering_id'))),
            ],
            'instructor_id' => $this->instructorAssignmentRules(),
            'modality' => ['required', Rule::enum(SectionModality::class)],
            'notes' => ['nullable', 'string'],
            'section_capacity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'enrolled_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $offering = Offering::where('id', $data['offering_id'])
            ->where('term_id', $term->id)
            ->first();
        abort_if(! $offering, 404, 'Offering not in this term.');

        $section = $sections->createSection($term, $offering, $data);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['term' => $term, 'focus' => $section->id])->with('status', 'Section created.');
        }

        return redirect()->route('aop.schedule.sections.index', $term)->with('status', 'Section created.');
    }

    public function edit(Term $term, Section $section, ScheduleConflictService $conflicts, ScheduleEventReadAdapter $eventReads)
    {
        $section->load(['offering.catalogCourse', 'instructor', 'meetingBlocks.room']);
        abort_if($section->offering->term_id !== $term->id, 404, 'Section not in this term.');
        $eventReads->meetingBlocks($section->meetingBlocks);

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
            'scheduleLocked' => false,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
            'rooms' => Room::where('is_active', true)->orderBy('name')->get(),
            'meetingBlockTypes' => MeetingBlockType::cases(),
            'weekDays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'conflictNotes' => $conflictNotes,
            'changeLogs' => $changeLogs,
        ]);
    }

    public function update(Request $request, Term $term, Section $section, SectionInstructorAssignmentService $assignments)
    {
        abort_if($section->offering->term_id !== $term->id, 404, 'Section not in this term.');

        $data = $request->validate([
            'section_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('sections', 'section_code')
                    ->where(fn ($q) => $q->where('offering_id', $section->offering_id))
                    ->ignore($section->id),
            ],
            'instructor_id' => $this->instructorAssignmentRules(),
            'modality' => ['required', Rule::enum(SectionModality::class)],
            'notes' => ['nullable', 'string'],
            'section_capacity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'enrolled_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $section = $assignments->updateSection($term, $section, $data);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['term' => $term, 'focus' => $section->id])->with('status', 'Section updated.');
        }

        return redirect()->route('aop.schedule.sections.edit', [$term, $section])->with('status', 'Section updated.');
    }

    public function destroy(Request $request, Term $term, Section $section, SectionInstructorAssignmentService $sections)
    {
        abort_if($section->offering->term_id !== $term->id, 404, 'Section does not belong to this term.');

        $offeringId = $section->offering_id;
        $label = ($section->offering->catalogCourse->code ?? 'Section').' '.$section->section_code;

        $sections->deleteSection($term, $section);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['term' => $term, 'focus_offering' => $offeringId])
                ->with('status', "{$label} removed.");
        }

        return redirect()->route('aop.schedule.sections.index', $term)
            ->with('status', "{$label} removed.");
    }

    public function bulkAssign(Request $request, Term $term, SectionInstructorAssignmentService $assignments)
    {
        $data = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'exists:sections,id'],
            'instructor_id' => $this->instructorAssignmentRules(),
        ]);

        $requestedSectionIds = collect($data['section_ids'])->map(fn ($id) => (int) $id)->unique();
        $termSectionCount = Section::query()
            ->whereIn('id', $requestedSectionIds)
            ->whereHas('offering', fn ($query) => $query->where('term_id', $term->id))
            ->count();
        abort_if($termSectionCount !== $requestedSectionIds->count(), 404, 'One or more sections do not belong to this term.');

        $sections = $assignments->assignMany($term, $data['section_ids'], $data['instructor_id'] ?? null);
        $updated = $sections->count();

        $first = $sections->first();
        $name = $first?->instructor_is_staff
            ? 'STAFF'
            : ($first?->instructor_id
                ? $first->instructor?->name ?? 'selected instructor'
                : 'Unassigned');

        return redirect()->route('aop.schedule.sections.index', $term)
            ->with('status', "Updated {$updated} section(s) — instructor set to {$name}.");
    }

    public function suggest(Request $request, Term $term, Section $section, ScheduleSuggestionService $service)
    {
        abort_if($section->offering->term_id !== $term->id, 404, 'Section not in this term.');

        $blockType = strtoupper((string) $request->input('block_type', 'LECTURE'));
        if (! in_array($blockType, ['LECTURE', 'LAB'], true)) {
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
            $roomConflictLabels = ScheduleConflictService::formatRoomConflictMessages($roomConflicts);
            if ($roomConflictLabels !== []) {
                $notes[] = $label.': room conflict(s) with '.implode('; ', $roomConflictLabels);
            }

            if (! empty($section->instructor_id)) {
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

    private function instructorAssignmentRules(): array
    {
        return [
            'nullable',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '' || $value === Section::STAFF_ASSIGNMENT) {
                    return;
                }

                if (! ctype_digit((string) $value) || ! Instructor::query()->whereKey((int) $value)->exists()) {
                    $fail('The selected instructor is invalid.');
                }
            },
        ];
    }
}
