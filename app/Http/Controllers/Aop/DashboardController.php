<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $activeTerm = Term::where('is_active', true)->first();

        if (!auth()->user()->is_admin) {
            $instructor = Instructor::where('email', auth()->user()->email)->first();
            
            if (!$instructor) {
                return view('aop.instructor_dashboard_empty'); // we will create this
            }

            // Grab their assigned sections and office hours
            $sections = [];
            $officeHours = [];
            $preferences = null;

            if ($activeTerm) {
                $sections = Section::with(['offering.catalogCourse', 'meetingBlocks.room'])
                    ->where('instructor_id', $instructor->id)
                    ->whereHas('offering', fn($q) => $q->where('term_id', $activeTerm->id))
                    ->get();
                
                $officeHours = \App\Models\OfficeHourBlock::where('instructor_id', $instructor->id)
                    ->where('term_id', $activeTerm->id)
                    ->get();

                $preferences = \App\Models\InstructorPreference::where('instructor_id', $instructor->id)
                    ->where('term_id', $activeTerm->id)
                    ->first();
            }

            return view('aop.instructor_dashboard', [
                'activeTerm' => $activeTerm,
                'instructor' => $instructor,
                'sections' => $sections,
                'officeHours' => $officeHours,
                'preferences' => $preferences,
            ]);
        }

        $counts = [
            'terms' => Term::count(),
            'catalog_courses' => CatalogCourse::count(),
            'instructors' => Instructor::count(),
            'rooms' => Room::count(),
            'sections' => Section::count(),
        ];

        return view('aop.dashboard', [
            'activeTerm' => $activeTerm,
            'counts' => $counts,
        ]);
    }
}
