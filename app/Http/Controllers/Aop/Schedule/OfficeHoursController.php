<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Data\Scheduling\OfficeHourCommand;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\InstructorPreference;
use App\Models\InstructorTermLock;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use App\Services\OfficeHourMutationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfficeHoursController extends Controller
{
    private const ALLOWED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(! $term, 400, 'No active term is set. Go to Terms and set an active term.');

        return $term;
    }

    private function lockFor(Term $term, Instructor $instructor): InstructorTermLock
    {
        return InstructorTermLock::for($term, $instructor);
    }

    private function ensureActiveInstructor(Instructor $instructor): void
    {
        abort_if(! $instructor->is_active, 404, 'Instructor not found.');
    }

    public function index(Term $term)
    {
        return view('aop.schedule.office-hours.index', [
            'term' => $term,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(Term $term, Instructor $instructor)
    {
        $this->ensureActiveInstructor($instructor);

        $lock = $this->lockFor($term, $instructor);

        $blocks = OfficeHourBlock::where('term_id', $term->id)
            ->where('instructor_id', $instructor->id)
            ->orderBy('starts_at')
            ->get();

        $preferences = InstructorPreference::where('term_id', $term->id)
            ->where('instructor_id', $instructor->id)
            ->first();

        return view('aop.schedule.office-hours.show', [
            'term' => $term,
            'instructor' => $instructor,
            'lock' => $lock,
            'blocks' => $blocks,
            'days' => self::ALLOWED_DAYS,
            'preferences' => $preferences,
        ]);
    }

    private function ensureUnlocked(Term $term, Instructor $instructor): void
    {
        $lock = $this->lockFor($term, $instructor);
        abort_if($lock->office_hours_locked, 403, 'Office hours are locked for this instructor in the requested term.');
    }

    private function ensureOwnerOrAdmin(Instructor $instructor): void
    {
        if (! auth()->user()->is_admin) {
            abort_unless((int) auth()->user()->instructor_id === (int) $instructor->id, 403, 'You can only manage your own office hours.');
        }
    }

    public function storeForPortal(Request $request, Instructor $instructor, OfficeHourMutationService $mutations)
    {
        return $this->store($request, $this->activeTermOrFail(), $instructor, $mutations);
    }

    public function store(Request $request, Term $term, Instructor $instructor, OfficeHourMutationService $mutations)
    {
        $this->ensureActiveInstructor($instructor);
        $this->ensureOwnerOrAdmin($instructor);
        $this->ensureUnlocked($term, $instructor);

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $mutations->create($term, $instructor, OfficeHourCommand::fromArray($data));

        if ($request->has('from_instructor_portal')) {
            return redirect(route('dashboard').'#office-hours')->with('status', 'Office hours added.');
        }

        return redirect()->route('aop.schedule.officeHours.show', [$term, $instructor])->with('status', 'Office hours block added.');
    }

    public function update(Request $request, Term $term, Instructor $instructor, OfficeHourBlock $officeHourBlock, OfficeHourMutationService $mutations)
    {
        $this->ensureActiveInstructor($instructor);
        $this->ensureOwnerOrAdmin($instructor);
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in requested term/instructor.');

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $mutations->update($term, $instructor, $officeHourBlock, OfficeHourCommand::fromArray($data));

        if ($request->has('from_instructor_portal')) {
            return redirect(route('dashboard').'#office-hours')->with('status', 'Office hours updated.');
        }

        return redirect()->route('aop.schedule.officeHours.show', [$term, $instructor])->with('status', 'Office hours block updated.');
    }

    public function updateForPortal(Request $request, Instructor $instructor, OfficeHourBlock $officeHourBlock, OfficeHourMutationService $mutations)
    {
        return $this->update($request, $this->activeTermOrFail(), $instructor, $officeHourBlock, $mutations);
    }

    public function destroyForPortal(Request $request, Instructor $instructor, OfficeHourBlock $officeHourBlock, OfficeHourMutationService $mutations)
    {
        return $this->destroy($request, $this->activeTermOrFail(), $instructor, $officeHourBlock, $mutations);
    }

    public function destroy(Request $request, Term $term, Instructor $instructor, OfficeHourBlock $officeHourBlock, OfficeHourMutationService $mutations)
    {
        $this->ensureActiveInstructor($instructor);
        $this->ensureOwnerOrAdmin($instructor);
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in requested term/instructor.');

        $mutations->delete($term, $instructor, $officeHourBlock);

        if ($request->has('from_instructor_portal')) {
            return redirect(route('dashboard').'#office-hours')->with('status', 'Office hours deleted.');
        }

        return redirect()->route('aop.schedule.officeHours.show', [$term, $instructor])->with('status', 'Office hours block deleted.');
    }

    public function lock(Request $request, Term $term, Instructor $instructor)
    {
        $this->ensureActiveInstructor($instructor);

        $lock = $this->lockFor($term, $instructor);
        $lock->update([
            'office_hours_locked' => true,
            'office_hours_locked_at' => now(),
            'office_hours_locked_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('aop.schedule.officeHours.show', [$term, $instructor])->with('status', 'Office hours locked for this instructor.');
    }

    public function unlock(Request $request, Term $term, Instructor $instructor)
    {
        $this->ensureActiveInstructor($instructor);

        $lock = $this->lockFor($term, $instructor);
        $lock->update([
            'office_hours_locked' => false,
            'office_hours_locked_at' => null,
            'office_hours_locked_by_user_id' => null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', [$term, $instructor])->with('status', 'Office hours unlocked for this instructor.');
    }
}
