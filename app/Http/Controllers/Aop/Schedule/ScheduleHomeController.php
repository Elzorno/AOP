<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\SchedulePublication;
use App\Models\Term;

class ScheduleHomeController extends Controller
{
    public function index()
    {
        $term = Term::where('is_active', true)->first();

        $latestPublication = null;
        if ($term) {
            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        return view('aop.schedule.index', [
            'term' => $term,
            'latestPublication' => $latestPublication,
        ]);
    }
}
