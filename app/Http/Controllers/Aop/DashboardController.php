<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\InstructorPreference;
use App\Models\InstructorTermLock;
use App\Models\OfficeHourBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $activeTerm = Term::where('is_active', true)->first();

        if (! auth()->user()->is_admin) {
            $instructor = auth()->user()->instructor;

            if (! $instructor) {
                return view('aop.instructor_dashboard_empty'); // we will create this
            }

            // Grab their assigned sections and office hours
            $sections = [];
            $officeHours = [];
            $preferences = null;
            $officeHoursLocked = false;

            if ($activeTerm) {
                $sections = Section::with(['offering.catalogCourse', 'meetingBlocks.room'])
                    ->where('instructor_id', $instructor->id)
                    ->whereHas('offering', fn ($q) => $q->where('term_id', $activeTerm->id))
                    ->orderBy('section_code')
                    ->get()
                    ->sortBy(fn (Section $section) => $section->offering->catalogCourse->code)
                    ->values();

                $officeHours = OfficeHourBlock::where('instructor_id', $instructor->id)
                    ->where('term_id', $activeTerm->id)
                    ->orderBy('starts_at')
                    ->get();

                $preferences = InstructorPreference::where('instructor_id', $instructor->id)
                    ->where('term_id', $activeTerm->id)
                    ->first();

                $officeHoursLocked = (bool) InstructorTermLock::query()
                    ->where('term_id', $activeTerm->id)
                    ->where('instructor_id', $instructor->id)
                    ->value('office_hours_locked');
            }

            return view('aop.instructor_dashboard', [
                'activeTerm' => $activeTerm,
                'instructor' => $instructor,
                'sections' => $sections,
                'officeHours' => $officeHours,
                'preferences' => $preferences,
                'officeHoursLocked' => $officeHoursLocked,
            ]);
        }

        $counts = [
            'terms' => Term::count(),
            'catalog_courses' => CatalogCourse::count(),
            'instructors' => Instructor::count(),
            'rooms' => Room::count(),
            'sections' => Section::count(),
        ];
        $workingTerm = $request->user()->selectedTerm ?? $activeTerm;
        $workingCounts = [
            'offerings' => $workingTerm ? Offering::query()->where('term_id', $workingTerm->id)->count() : 0,
            'sections' => $workingTerm
                ? Section::query()->whereHas('offering', fn ($query) => $query->where('term_id', $workingTerm->id))->count()
                : 0,
            'unassigned_sections' => $workingTerm
                ? Section::query()
                    ->whereHas('offering', fn ($query) => $query->where('term_id', $workingTerm->id))
                    ->whereNull('instructor_id')
                    ->where('instructor_is_staff', false)
                    ->count()
                : 0,
        ];

        return view('aop.dashboard', [
            'activeTerm' => $activeTerm,
            'workingTerm' => $workingTerm,
            'counts' => $counts,
            'workingCounts' => $workingCounts,
        ]);
    }
}
