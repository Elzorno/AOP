<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Term;

class ScheduleHomeController extends Controller
{
    public function index()
    {
        $term = Term::where('is_active', true)->first();

        return view('aop.schedule.index', [
            'term' => $term,
        ]);
    }
}
