<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\InstructorTermLock;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class OfficeHoursController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    private function lockFor(Term $term, Instructor $instructor): InstructorTermLock
    {
        return InstructorTermLock::for($term, $instructor);
    }

    public function index()
    {
        $term = Term::where('is_active', true)->first();

        return view('aop.schedule.office-hours.index', [
            'term' => $term,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

        abort_if(!$instructor->is_active, 404, 'Instructor not found.');

        $lock = $this->lockFor($term, $instructor);

        $blocks = OfficeHourBlock::where('term_id', $term->id)
            ->where('instructor_id', $instructor->id)
            ->orderBy('starts_at')
            ->get();

        return view('aop.schedule.office-hours.show', [
            'term' => $term,
            'instructor' => $instructor,
            'lock' => $lock,
            'blocks' => $blocks,
            'days' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
        ]);
    }

    private function ensureUnlocked(Term $term, Instructor $instructor): void
    {
        $lock = $this->lockFor($term, $instructor);
        abort_if($lock->office_hours_locked, 403, 'Office hours are locked for this instructor in the active term.');
    }

    public function store(Request $request, Instructor $instructor, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();
        $this->ensureUnlocked($term, $instructor);

        $data = $request->validate([
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        $days = array_values($data['days']);

        $conf = $conflicts->instructorConflictsForOfficeHourBlock($term, $instructor->id, $days, $data['starts_at'], $data['ends_at']);

        $messages = [];

        if ($conf['office_hour_blocks']->count() > 0) {
            $messages[] = 'Conflicts with existing office hours: ' . $conf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
        }

        if ($conf['meeting_blocks']->count() > 0) {
            $messages[] = 'Conflicts with class meeting blocks: ' . $conf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
        }

        if (!empty($messages)) {
            return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
        }

        OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block added.');
    }

    public function update(Request $request, Instructor $instructor, OfficeHourBlock $officeHourBlock, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in active term/instructor.');

        $data = $request->validate([
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        $days = array_values($data['days']);

        $conf = $conflicts->instructorConflictsForOfficeHourBlock($term, $instructor->id, $days, $data['starts_at'], $data['ends_at'], $officeHourBlock->id);

        $messages = [];

        if ($conf['office_hour_blocks']->count() > 0) {
            $messages[] = 'Conflicts with existing office hours: ' . $conf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
        }

        if ($conf['meeting_blocks']->count() > 0) {
            $messages[] = 'Conflicts with class meeting blocks: ' . $conf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
        }

        if (!empty($messages)) {
            return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
        }

        $officeHourBlock->update([
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block updated.');
    }

    public function destroy(Request $request, Instructor $instructor, OfficeHourBlock $officeHourBlock)
    {
        $term = $this->activeTermOrFail();
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in active term/instructor.');

        $officeHourBlock->delete();

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block deleted.');
    }

    public function lock(Request $request, Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

        $lock = $this->lockFor($term, $instructor);
        $lock->update([
            'office_hours_locked' => true,
            'office_hours_locked_at' => now(),
            'office_hours_locked_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours locked for this instructor (active term).');
    }

    public function unlock(Request $request, Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

        $lock = $this->lockFor($term, $instructor);
        $lock->update([
            'office_hours_locked' => false,
            'office_hours_locked_at' => null,
            'office_hours_locked_by_user_id' => null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours unlocked for this instructor (active term).');
    }
}
