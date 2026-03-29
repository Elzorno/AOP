<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class ScheduleHomeController extends Controller
{
    public function index(Request $request)
    {
        $term = Term::where('is_active', true)->first();

        $latestPublication = null;
        if ($term) {
            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        $summary = $term ? $this->buildTermSummary($term, $latestPublication) : null;
        $workspace = $term ? $this->buildWorkspace($term, $request) : $this->emptyWorkspace();

        return view('aop.schedule.index', array_merge([
            'term' => $term,
            'latestPublication' => $latestPublication,
            'summary' => $summary,
        ], $workspace));
    }

    private function emptyWorkspace(): array
    {
        return [
            'availableCourses' => collect(),
            'instructors' => collect(),
            'rooms' => collect(),
            'offerings' => collect(),
            'issueQueue' => collect(),
            'supportLinks' => [],
            'filters' => [
                'q' => '',
                'issue' => 'all',
            ],
            'focusSectionId' => null,
            'focusOfferingId' => null,
            'meetingBlockTypes' => MeetingBlockType::cases(),
            'modalities' => SectionModality::cases(),
            'weekDays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        ];
    }

    private function buildWorkspace(Term $term, Request $request): array
    {
        $queryText = trim((string) $request->input('q', ''));
        $issueFilter = (string) $request->input('issue', 'all');
        $validIssueFilters = ['all', 'attention', 'missing_instructor', 'missing_meetings', 'missing_room', 'conflicts', 'ready'];
        if (!in_array($issueFilter, $validIssueFilters, true)) {
            $issueFilter = 'all';
        }

        $focusSectionId = $request->filled('focus') ? max(1, (int) $request->input('focus')) : null;
        $focusOfferingId = $request->filled('focus_offering') ? max(1, (int) $request->input('focus_offering')) : null;

        $offerings = Offering::query()
            ->with([
                'catalogCourse',
                'sections.instructor',
                'sections.meetingBlocks.room',
            ])
            ->where('term_id', $term->id)
            ->get()
            ->sortBy(fn (Offering $offering) => sprintf(
                '%s|%s|%08d',
                mb_strtolower((string) ($offering->catalogCourse?->code ?? '')),
                mb_strtolower((string) ($offering->catalogCourse?->title ?? '')),
                (int) $offering->id,
            ))
            ->values();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->with('instructor')
            ->where('term_id', $term->id)
            ->get();

        $conflictMaps = $this->buildConflictMaps($term, $meetingBlocks, $officeBlocks);

        $offeringCards = $offerings->map(function (Offering $offering) use (
            $queryText,
            $issueFilter,
            $focusSectionId,
            $focusOfferingId,
            $conflictMaps
        ) {
            $course = $offering->catalogCourse;
            $courseSearchMatch = $this->matchesText(
                trim(($course?->code ?? '').' '.($course?->title ?? '')),
                $queryText
            );

            $sectionCards = $offering->sections
                ->sortBy('section_code')
                ->values()
                ->map(function (Section $section) use ($conflictMaps, $queryText, $course) {
                    $meetingCards = $section->meetingBlocks
                        ->sortBy(fn (MeetingBlock $meetingBlock) => sprintf(
                            '%s|%s|%s',
                            (string) $meetingBlock->starts_at,
                            (string) $meetingBlock->ends_at,
                            implode(',', $meetingBlock->days_json ?? []),
                        ))
                        ->values()
                        ->map(function (MeetingBlock $meetingBlock) use ($section, $conflictMaps) {
                            $roomRequired = $section->modality?->value !== SectionModality::ONLINE->value;

                            return [
                                'model' => $meetingBlock,
                                'missing_room' => $roomRequired && empty($meetingBlock->room_id),
                                'room_conflict_count' => $conflictMaps['room_block_counts'][$meetingBlock->id] ?? 0,
                                'instructor_conflict_count' => $conflictMaps['instructor_block_counts'][$meetingBlock->id] ?? 0,
                            ];
                        });

                    $roomRequired = $section->modality?->value !== SectionModality::ONLINE->value;
                    $hasMissingInstructor = empty($section->instructor_id);
                    $hasMissingMeetings = $meetingCards->isEmpty();
                    $hasMissingRoom = $roomRequired && $meetingCards->contains(fn (array $meetingCard) => $meetingCard['missing_room']);
                    $roomConflictCount = $conflictMaps['room_section_counts'][$section->id] ?? 0;
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

                    $searchText = trim(
                        ($course?->code ?? '').' '.
                        ($course?->title ?? '').' '.
                        $section->section_code.' '.
                        ($section->instructor?->name ?? '')
                    );

                    return [
                        'id' => $section->id,
                        'model' => $section,
                        'meeting_cards' => $meetingCards->all(),
                        'conflict_notes' => $conflictMaps['section_notes'][$section->id] ?? [],
                        'has_missing_instructor' => $hasMissingInstructor,
                        'has_missing_meetings' => $hasMissingMeetings,
                        'has_missing_room' => $hasMissingRoom,
                        'room_conflict_count' => $roomConflictCount,
                        'instructor_conflict_count' => $instructorConflictCount,
                        'issue_count' => $issueCount,
                        'issue_badges' => $issueBadges,
                        'is_ready' => $issueCount === 0,
                        'matches_search' => $this->matchesText($searchText, $queryText),
                    ];
                });

            $visibleSections = $sectionCards->filter(function (array $sectionCard) use ($queryText, $issueFilter, $courseSearchMatch) {
                if ($queryText !== '' && !$courseSearchMatch && !$sectionCard['matches_search']) {
                    return false;
                }

                return $this->matchesIssueFilter($sectionCard, $issueFilter);
            })->values();

            if ($queryText !== '' && $courseSearchMatch && $issueFilter === 'all') {
                $visibleSections = $sectionCards->values();
            }

            $hasEmptyOffering = $sectionCards->isEmpty();
            $showOffering = $visibleSections->isNotEmpty()
                || ($queryText === '' && $issueFilter === 'all')
                || ($queryText !== '' && $courseSearchMatch && $issueFilter === 'all')
                || ($hasEmptyOffering && in_array($issueFilter, ['all', 'attention'], true) && ($queryText === '' || $courseSearchMatch));

            return [
                'model' => $offering,
                'course' => $course,
                'sections' => $visibleSections->all(),
                'all_sections_count' => $sectionCards->count(),
                'issue_count' => $sectionCards->sum('issue_count') + ($hasEmptyOffering ? 1 : 0),
                'has_empty_offering' => $hasEmptyOffering,
                'focus' => $focusOfferingId === $offering->id || $sectionCards->contains(fn (array $card) => $card['id'] === $focusSectionId),
                'show' => $showOffering,
            ];
        })->filter(fn (array $offeringCard) => $offeringCard['show'])->values();

        $allSectionCards = $offeringCards->pluck('sections')->flatten(1);

        $issueQueue = $allSectionCards
            ->filter(fn (array $sectionCard) => $sectionCard['issue_count'] > 0)
            ->sortByDesc('issue_count')
            ->take(8)
            ->map(function (array $sectionCard) {
                $section = $sectionCard['model'];
                $course = $section->offering?->catalogCourse;

                return [
                    'label' => trim(($course?->code ?? 'COURSE').' '.$section->section_code),
                    'course_title' => (string) ($course?->title ?? ''),
                    'issue_count' => $sectionCard['issue_count'],
                    'url' => route('aop.schedule.home', ['focus' => $section->id]).'#section-'.$section->id,
                ];
            })
            ->values();

        $offeredCourseIds = $offerings->pluck('catalog_course_id')->filter()->unique()->all();
        $availableCourses = CatalogCourse::query()
            ->where('is_active', true)
            ->when($offeredCourseIds !== [], fn ($q) => $q->whereNotIn('id', $offeredCourseIds))
            ->orderBy('code')
            ->get();

        return [
            'availableCourses' => $availableCourses,
            'instructors' => Instructor::query()->where('is_active', true)->orderBy('name')->get(),
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'offerings' => $offeringCards,
            'issueQueue' => $issueQueue,
            'supportLinks' => [
                ['label' => 'Readiness', 'href' => route('aop.schedule.readiness.index')],
                ['label' => 'Calendar', 'href' => route('aop.schedule.calendar.index')],
                ['label' => 'Reports', 'href' => route('aop.schedule.reports.index')],
                ['label' => 'Office Hours', 'href' => route('aop.schedule.officeHours.index')],
                ['label' => 'Rooms', 'href' => route('aop.rooms.index')],
                ['label' => 'Syllabi', 'href' => route('aop.syllabi.index')],
            ],
            'filters' => [
                'q' => $queryText,
                'issue' => $issueFilter,
            ],
            'focusSectionId' => $focusSectionId,
            'focusOfferingId' => $focusOfferingId,
            'meetingBlockTypes' => MeetingBlockType::cases(),
            'modalities' => SectionModality::cases(),
            'weekDays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        ];
    }

    private function buildConflictMaps(Term $term, $meetingBlocks, $officeBlocks): array
    {
        $roomSectionCounts = [];
        $instructorSectionCounts = [];
        $roomBlockCounts = [];
        $instructorBlockCounts = [];
        $sectionNotes = [];

        $byRoom = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');
        foreach ($byRoom as $blocks) {
            $list = $blocks->values();
            $count = $list->count();

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];

                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }

                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }

                    $roomSectionCounts[$a->section_id] = ($roomSectionCounts[$a->section_id] ?? 0) + 1;
                    $roomSectionCounts[$b->section_id] = ($roomSectionCounts[$b->section_id] ?? 0) + 1;
                    $roomBlockCounts[$a->id] = ($roomBlockCounts[$a->id] ?? 0) + 1;
                    $roomBlockCounts[$b->id] = ($roomBlockCounts[$b->id] ?? 0) + 1;

                    $labelA = ScheduleConflictService::formatMeetingBlockLabel($a);
                    $labelB = ScheduleConflictService::formatMeetingBlockLabel($b);
                    $roomName = $a->room?->name ?? 'Room';

                    $sectionNotes[$a->section_id][] = $roomName.' conflict with '.$labelB;
                    $sectionNotes[$b->section_id][] = $roomName.' conflict with '.$labelA;
                }
            }
        }

        $meetingByInstructor = $meetingBlocks
            ->filter(fn (MeetingBlock $meetingBlock) => (bool) $meetingBlock->section?->instructor_id)
            ->groupBy(fn (MeetingBlock $meetingBlock) => (int) $meetingBlock->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $instructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all(),
        )));

        foreach ($instructorIds as $instructorId) {
            $classList = ($meetingByInstructor[$instructorId] ?? collect())->values();
            $officeList = ($officeByInstructor[$instructorId] ?? collect())->values();

            $classCount = $classList->count();
            for ($i = 0; $i < $classCount; $i++) {
                for ($j = $i + 1; $j < $classCount; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];

                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }

                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }

                    $instructorSectionCounts[$a->section_id] = ($instructorSectionCounts[$a->section_id] ?? 0) + 1;
                    $instructorSectionCounts[$b->section_id] = ($instructorSectionCounts[$b->section_id] ?? 0) + 1;
                    $instructorBlockCounts[$a->id] = ($instructorBlockCounts[$a->id] ?? 0) + 1;
                    $instructorBlockCounts[$b->id] = ($instructorBlockCounts[$b->id] ?? 0) + 1;

                    $labelA = ScheduleConflictService::formatMeetingBlockLabel($a);
                    $labelB = ScheduleConflictService::formatMeetingBlockLabel($b);

                    $sectionNotes[$a->section_id][] = 'Instructor conflict with '.$labelB;
                    $sectionNotes[$b->section_id][] = 'Instructor conflict with '.$labelA;
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($officeList as $officeBlock) {
                    if (!ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $officeBlock->days_json ?? [])) {
                        continue;
                    }

                    if (!ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $officeBlock->starts_at, $officeBlock->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }

                    $instructorSectionCounts[$classBlock->section_id] = ($instructorSectionCounts[$classBlock->section_id] ?? 0) + 1;
                    $instructorBlockCounts[$classBlock->id] = ($instructorBlockCounts[$classBlock->id] ?? 0) + 1;
                    $sectionNotes[$classBlock->section_id][] = 'Instructor conflict with '.ScheduleConflictService::formatOfficeHourLabel($officeBlock);
                }
            }
        }

        foreach ($sectionNotes as $sectionId => $notes) {
            $sectionNotes[$sectionId] = array_values(array_unique($notes));
        }

        return [
            'room_section_counts' => $roomSectionCounts,
            'instructor_section_counts' => $instructorSectionCounts,
            'room_block_counts' => $roomBlockCounts,
            'instructor_block_counts' => $instructorBlockCounts,
            'section_notes' => $sectionNotes,
        ];
    }

    private function matchesIssueFilter(array $sectionCard, string $issueFilter): bool
    {
        return match ($issueFilter) {
            'attention' => !$sectionCard['is_ready'],
            'missing_instructor' => $sectionCard['has_missing_instructor'],
            'missing_meetings' => $sectionCard['has_missing_meetings'],
            'missing_room' => $sectionCard['has_missing_room'],
            'conflicts' => $sectionCard['room_conflict_count'] > 0 || $sectionCard['instructor_conflict_count'] > 0,
            'ready' => $sectionCard['is_ready'],
            default => true,
        };
    }

    private function matchesText(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    private function buildTermSummary(Term $term, ?SchedulePublication $latestPublication): array
    {
        $offeringsCount = Offering::where('term_id', $term->id)->count();

        $sections = Section::query()
            ->with(['meetingBlocks', 'instructor'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.instructor'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->get();

        return [
            'offerings_count' => $offeringsCount,
            'sections_count' => $sections->count(),
            'sections_missing_instructor_count' => $sections->whereNull('instructor_id')->count(),
            'sections_missing_meeting_blocks_count' => $sections->filter(fn ($section) => $section->meetingBlocks->count() === 0)->count(),
            'meeting_blocks_missing_room_count' => $meetingBlocks->whereNull('room_id')->count(),
            'room_conflict_count' => $this->countRoomConflicts($term, $meetingBlocks),
            'instructor_conflict_count' => $this->countInstructorConflicts($term, $meetingBlocks, $officeBlocks),
            'office_hours_failing_count' => $this->countOfficeHoursFailing($term, $officeBlocks),
            'latest_publication_version' => $latestPublication?->version,
            'term_status' => (string) ($term->status ?? 'draft'),
            'schedule_locked' => (bool) ($term->schedule_locked ?? false),
        ];
    }

    private function countRoomConflicts(Term $term, $meetingBlocks): int
    {
        $count = 0;
        $grouped = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');

        foreach ($grouped as $blocks) {
            $list = $blocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countInstructorConflicts(Term $term, $meetingBlocks, $officeBlocks): int
    {
        $count = 0;

        $meetingByInstructor = $meetingBlocks
            ->filter(fn ($meetingBlock) => (bool) $meetingBlock->section?->instructor_id)
            ->groupBy(fn ($meetingBlock) => (int) $meetingBlock->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $allInstructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all(),
        )));

        foreach ($allInstructorIds as $instructorId) {
            $classList = ($meetingByInstructor[$instructorId] ?? collect())->values();
            $officeList = ($officeByInstructor[$instructorId] ?? collect())->values();

            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($officeList as $officeBlock) {
                    if (!ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $officeBlock->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $officeBlock->starts_at, $officeBlock->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countOfficeHoursFailing(Term $term, $officeBlocks): int
    {
        $fullTimeInstructors = Instructor::query()
            ->where('is_active', true)
            ->where('is_full_time', true)
            ->get();

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');
        $failing = 0;

        foreach ($fullTimeInstructors as $instructor) {
            $blocks = ($officeByInstructor[$instructor->id] ?? collect())->values();
            $minutesPerWeek = 0;
            $days = [];

            foreach ($blocks as $block) {
                $blockDays = ScheduleConflictService::normalizeDays($block->days_json ?? []);
                $days = array_merge($days, $blockDays);
                $minutesPerWeek += $this->durationMinutes((string) $block->starts_at, (string) $block->ends_at) * count($blockDays);
            }

            $meetsHours = $minutesPerWeek >= 240;
            $meetsDays = count(array_unique($days)) >= 3;

            if (!($meetsHours && $meetsDays)) {
                $failing++;
            }
        }

        return $failing;
    }

    private function durationMinutes(string $startsAt, string $endsAt): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', substr($startsAt, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($endsAt, 0, 5)));
        $start = ($startHour * 60) + $startMinute;
        $end = ($endHour * 60) + $endMinute;

        return max(0, $end - $start);
    }
}
