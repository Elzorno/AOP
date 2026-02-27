<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\InstructorTermLock;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Carbon\Carbon;

class ScheduleReadinessController extends Controller
{
    /**
     * Instructional minutes rule (ODHE / SSU):
     *
     * Required minutes per TERM are computed from the course's WEEKLY contact hours,
     * converted into credit-hour equivalents:
     *
     *   lecture_credit_hours = lecture_contact_hours
     *   lab_credit_hours     = lab_contact_hours / 3
     *
     * Then:
     *   required_minutes_base_15w = (lecture_credit_hours * 750) + (lab_credit_hours * 2250)
     *
     * We scale linearly if Term::weeks_in_term is not 15:
     *   required_minutes = required_minutes_base_15w * (weeks_in_term / 15)
     */
    private const LECTURE_MINUTES_PER_CREDIT_15W = 750;
    private const LAB_MINUTES_PER_CREDIT_15W = 2250;
    private const BASE_WEEKS = 15;

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function index()
    {
        $term = $this->activeTermOrFail();

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks.room'])
            ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('id', 'desc')
            ->get();

        $sectionsMissingInstructor = $sections->filter(fn($s) => !$s->instructor_id)->values();
        $sectionsMissingMeetingBlocks = $sections->filter(fn($s) => $s->meetingBlocks->count() === 0)->values();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('starts_at')
            ->get();

        $meetingBlocksMissingRoom = $meetingBlocks->filter(fn($mb) => !$mb->room_id)->values();

        $officeBlocks = OfficeHourBlock::query()
            ->with('instructor')
            ->where('term_id', $term->id)
            ->orderBy('starts_at')
            ->get();

        $roomConflicts = $this->computeRoomConflicts($meetingBlocks);
        $instructorConflicts = $this->computeInstructorConflicts($meetingBlocks, $officeBlocks);

        $officeHoursCompliance = $this->computeOfficeHoursCompliance($term, $officeBlocks);
        $officeHoursFailing = collect($officeHoursCompliance)
            ->filter(fn($r) => (bool)$r['is_full_time'] && !(bool)$r['pass'])
            ->values();

        $instructionalMinutes = $this->computeInstructionalMinutes($term, $sections);
        $minutesFailing = collect($instructionalMinutes)->where('pass', false)->values();

        return view('aop.schedule.readiness.index', [
            'term' => $term,
            'sectionsMissingInstructor' => $sectionsMissingInstructor,
            'sectionsMissingMeetingBlocks' => $sectionsMissingMeetingBlocks,
            'meetingBlocksMissingRoom' => $meetingBlocksMissingRoom,
            'roomConflicts' => $roomConflicts,
            'instructorConflicts' => $instructorConflicts,
            'officeHoursCompliance' => $officeHoursCompliance,
            'officeHoursFailing' => $officeHoursFailing,
            'instructionalMinutes' => $instructionalMinutes,
            'minutesFailing' => $minutesFailing,
        ]);
    }

    private function computeInstructionalMinutes(Term $term, $sections): array
    {
        $weeks = (int)($term->weeks_in_term ?? self::BASE_WEEKS);
        if ($weeks <= 0) $weeks = self::BASE_WEEKS;

        $scale = $weeks / self::BASE_WEEKS;

        $results = [];

        foreach ($sections as $section) {
            $course = $section->offering?->catalogCourse;

            $lectureContact = (float)($course?->lecture_hours_per_week ?? 0);
            $labContact = (float)($course?->lab_hours_per_week ?? 0);
            $fallbackContact = (float)($course?->contact_hours_per_week ?? 0);

            // If lecture/lab split isn't provided, treat fallback as lecture contact hours.
            if (($lectureContact + $labContact) <= 0 && $fallbackContact > 0) {
                $lectureContact = $fallbackContact;
                $labContact = 0;
            }

            $lectureCredits = $lectureContact;
            $labCredits = $labContact / 3.0;

            $requiredBase15 = ($lectureCredits * self::LECTURE_MINUTES_PER_CREDIT_15W)
                + ($labCredits * self::LAB_MINUTES_PER_CREDIT_15W);

            $requiredMinutes = (int)round($requiredBase15 * $scale);

            // scheduled_minutes_per_week = sum(duration_minutes * number_of_days)
            $scheduledPerWeek = 0;
            foreach ($section->meetingBlocks as $mb) {
                $duration = $this->durationMinutes($mb->starts_at, $mb->ends_at);
                $daysCount = $this->daysCount($mb->days_json);
                $scheduledPerWeek += $duration * $daysCount;
            }

            $scheduledMinutes = (int)round($scheduledPerWeek * $weeks);
            $delta = $scheduledMinutes - $requiredMinutes;
            $pass = $requiredMinutes === 0 ? true : ($scheduledMinutes >= $requiredMinutes);

            $results[] = [
                'section' => $section,
                'course' => $course,
                'weeks' => $weeks,
                'lecture_contact_hours' => $lectureContact,
                'lab_contact_hours' => $labContact,
                'lecture_credits' => $lectureCredits,
                'lab_credits' => $labCredits,
                'required_minutes' => $requiredMinutes,
                'scheduled_minutes' => $scheduledMinutes,
                'delta_minutes' => $delta,
                'pass' => $pass,
            ];
        }

        // Sort failing first, then most negative delta.
        usort($results, function ($a, $b) {
            if ($a['pass'] !== $b['pass']) return $a['pass'] ? 1 : -1;
            return ($a['delta_minutes'] <=> $b['delta_minutes']);
        });

        return $results;
    }


    private function computeOfficeHoursCompliance(Term $term, $officeBlocks): array
    {
        // Pull active instructors; show full-time requirement PASS/FAIL.
        $instructors = Instructor::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Lock rows (do NOT create missing rows here; readiness should be read-only).
        $locks = InstructorTermLock::query()
            ->where('term_id', $term->id)
            ->get()
            ->keyBy('instructor_id');

        $byInstructor = $officeBlocks->groupBy('instructor_id');

        $results = [];

        foreach ($instructors as $ins) {
            $blocks = ($byInstructor[$ins->id] ?? collect())->values();

            $minutesPerWeek = 0;
            $dayTokens = [];

            foreach ($blocks as $b) {
                $duration = $this->durationMinutes($b->starts_at, $b->ends_at);
                $days = $b->days_json;

                // days_json is cast to array; still guard for string/null
                if (is_string($days)) {
                    $decoded = json_decode($days, true);
                    $days = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $days)));
                }
                if (is_array($days)) {
                    foreach ($days as $d) {
                        if (is_string($d) && trim($d) !== '') $dayTokens[] = trim($d);
                    }
                }

                $minutesPerWeek += $duration * $this->daysCount($b->days_json);
            }

            $distinctDays = count(array_unique($dayTokens));
            $hoursPerWeek = $minutesPerWeek / 60.0;

            $isFullTime = (bool)$ins->is_full_time;
            $meetsHours = $minutesPerWeek >= 240; // 4 hours/week
            $meetsDays = $distinctDays >= 3;

            $pass = $isFullTime ? ($meetsHours && $meetsDays) : true;

            $lock = $locks[$ins->id] ?? null;
            $locked = (bool)($lock?->office_hours_locked ?? false);

            $results[] = [
                'instructor' => $ins,
                'is_full_time' => $isFullTime,
                'locked' => $locked,
                'minutes_per_week' => (int)$minutesPerWeek,
                'hours_per_week' => $hoursPerWeek,
                'distinct_days' => $distinctDays,
                'meets_hours' => $meetsHours,
                'meets_days' => $meetsDays,
                'pass' => $pass,
            ];
        }

        // Sort failing full-time first, then by most deficient hours.
        usort($results, function ($a, $b) {
            if ($a['pass'] !== $b['pass']) return $a['pass'] ? 1 : -1;
            return ($a['minutes_per_week'] <=> $b['minutes_per_week']);
        });

        return $results;
    }


    private function daysCount($daysJson): int
    {
        // Eloquent cast usually gives array; guard for legacy string.
        $days = $daysJson;

        if (is_string($days)) {
            $decoded = json_decode($days, true);
            if (is_array($decoded)) {
                $days = $decoded;
            } else {
                // last-resort: split by comma
                $parts = array_filter(array_map('trim', explode(',', $days)));
                $days = $parts;
            }
        }

        if (!is_array($days)) return 0;

        // Only count non-empty day tokens.
        $days = array_values(array_filter($days, fn($d) => is_string($d) && trim($d) !== ''));
        return max(0, count($days));
    }

    private function durationMinutes($startsAt, $endsAt): int
    {
        try {
            $s = Carbon::parse($startsAt);
            $e = Carbon::parse($endsAt);
            $diff = $s->diffInMinutes($e, false);
            return max(0, $diff);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function computeRoomConflicts($meetingBlocks)
    {
        $conflicts = [];
        $byRoom = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');

        foreach ($byRoom as $roomId => $blocks) {
            $list = $blocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;

                    $conflicts[] = [
                        'room' => $a->room,
                        'a' => $a,
                        'b' => $b,
                    ];
                }
            }
        }

        return $conflicts;
    }

    private function computeInstructorConflicts($meetingBlocks, $officeBlocks)
    {
        $conflicts = [];

        $meetingByInstructor = $meetingBlocks
            ->filter(fn($mb) => (bool)$mb->section?->instructor_id)
            ->groupBy(fn($mb) => (int)$mb->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $allInstructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all()
        )));

        foreach ($allInstructorIds as $insId) {
            $classList = ($meetingByInstructor[$insId] ?? collect())->values();
            $officeList = ($officeByInstructor[$insId] ?? collect())->values();

            // class vs class
            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $a->section->instructor,
                        'type' => 'CLASS vs CLASS',
                        'a_label' => ScheduleConflictService::formatMeetingBlockLabel($a),
                        'b_label' => ScheduleConflictService::formatMeetingBlockLabel($b),
                        'a_section_id' => $a->section_id,
                        'b_section_id' => $b->section_id,
                    ];
                }
            }

            // office vs office
            $m = $officeList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $a->instructor,
                        'type' => 'OFFICE vs OFFICE',
                        'a_label' => ScheduleConflictService::formatOfficeHourLabel($a),
                        'b_label' => ScheduleConflictService::formatOfficeHourLabel($b),
                        'a_section_id' => null,
                        'b_section_id' => null,
                    ];
                }
            }

            // class vs office
            foreach ($classList as $c) {
                foreach ($officeList as $o) {
                    if (!ScheduleConflictService::dayOverlap($c->days_json ?? [], $o->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($c->starts_at, $c->ends_at, $o->starts_at, $o->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $c->section->instructor,
                        'type' => 'CLASS vs OFFICE',
                        'a_label' => ScheduleConflictService::formatMeetingBlockLabel($c),
                        'b_label' => ScheduleConflictService::formatOfficeHourLabel($o),
                        'a_section_id' => $c->section_id,
                        'b_section_id' => null,
                    ];
                }
            }
        }

        return $conflicts;
    }
}
