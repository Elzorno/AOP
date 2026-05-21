<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\InstructorPreference;
use App\Models\Term;
use Illuminate\Http\Request;

class InstructorPreferenceController extends Controller
{
    private const ALLOWED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set.');
        return $term;
    }

    /**
     * Create or update preferences for this instructor + active term.
     * Unavailable times are submitted as parallel arrays:
     *   unavail_day[], unavail_start[], unavail_end[]
     */
    public function upsert(Request $request, Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

        $data = $request->validate([
            'max_courses'    => ['nullable', 'integer', 'min:0', 'max:20'],
            'preferred_days' => ['nullable', 'array'],
            'preferred_days.*' => ['string', 'in:'.implode(',', self::ALLOWED_DAYS)],
            'unavail_day'    => ['nullable', 'array'],
            'unavail_day.*'  => ['string', 'in:'.implode(',', self::ALLOWED_DAYS)],
            'unavail_start'  => ['nullable', 'array'],
            'unavail_start.*'=> ['date_format:H:i'],
            'unavail_end'    => ['nullable', 'array'],
            'unavail_end.*'  => ['date_format:H:i'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ]);

        // Build unavailable_times array from parallel arrays
        $unavailableTimes = [];
        $unavailDays   = $data['unavail_day']   ?? [];
        $unavailStarts = $data['unavail_start'] ?? [];
        $unavailEnds   = $data['unavail_end']   ?? [];

        foreach ($unavailDays as $i => $day) {
            $start = $unavailStarts[$i] ?? null;
            $end   = $unavailEnds[$i]   ?? null;
            if ($day && $start && $end && $end > $start) {
                $unavailableTimes[] = ['day' => $day, 'starts_at' => $start, 'ends_at' => $end];
            }
        }

        InstructorPreference::updateOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            [
                'max_courses'       => $data['max_courses'] ?? 3,
                'preferred_days'    => array_values(array_unique($data['preferred_days'] ?? [])),
                'unavailable_times' => $unavailableTimes,
                'notes'             => $data['notes'] ?? null,
            ]
        );

        return redirect()
            ->route('aop.schedule.officeHours.show', $instructor)
            ->with('status', 'Scheduling preferences saved.');
    }

    public function destroy(Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

        InstructorPreference::where('term_id', $term->id)
            ->where('instructor_id', $instructor->id)
            ->delete();

        return redirect()
            ->route('aop.schedule.officeHours.show', $instructor)
            ->with('status', 'Scheduling preferences cleared.');
    }
}
