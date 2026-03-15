<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\InstructorPreference;
use App\Models\Term;
use Illuminate\Http\Request;

class InstructorPreferenceController extends Controller
{
    public function store(Request $request)
    {
        $term = Term::where('is_active', true)->firstOrFail();
        
        $instructor = Instructor::where('email', auth()->user()->email)->firstOrFail();

        $data = $request->validate([
            'max_courses' => 'required|integer|min:1',
            'preferred_days' => 'nullable|string',
            'unavailable_times_raw' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $prefDays = null;
        if (!empty($data['preferred_days'])) {
            $prefDays = array_values(array_filter(array_map('trim', explode(',', $data['preferred_days']))));
        }

        $unavailRaw = null;
        if (!empty($data['unavailable_times_raw'])) {
            $unavailRaw = array_values(array_filter(array_map('trim', explode("\n", $data['unavailable_times_raw']))));
        }

        InstructorPreference::updateOrCreate(
            ['instructor_id' => $instructor->id, 'term_id' => $term->id],
            [
                'max_courses' => $data['max_courses'],
                'preferred_days' => $prefDays,
                'unavailable_times' => $unavailRaw,
                'notes' => $data['notes'] ?? null,
            ]
        );

        return redirect()->route('dashboard')->with('status', 'Preferences saved.');
    }
}
