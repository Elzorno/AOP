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
