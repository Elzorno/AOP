<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\InstructorPreference;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstructorPreferenceController extends Controller
{
    public function store(Request $request)
    {
        $term = Term::where('is_active', true)->firstOrFail();

        $instructor = auth()->user()->instructor;
        abort_unless($instructor, 403, 'Your account is not linked to an instructor profile.');

        $data = $request->validate([
            'max_courses' => ['required', 'integer', 'min:1', 'max:20'],
            'preferred_days' => ['nullable', 'array'],
            'preferred_days.*' => ['string', Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']), 'distinct'],
            'unavailable_times_raw' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $prefDays = array_values($data['preferred_days'] ?? []);

        $unavailRaw = null;
        if (! empty($data['unavailable_times_raw'])) {
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

        return redirect(route('dashboard').'#preferences')->with('status', 'Teaching preferences saved.');
    }
}
