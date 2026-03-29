<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\CatalogCourse;
use App\Models\Offering;
use App\Models\Term;
use Illuminate\Http\Request;

class OfferingController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function index()
    {
        $term = $this->activeTermOrFail();

        $offerings = Offering::with('catalogCourse')
            ->withCount('sections')
            ->where('term_id', $term->id)
            ->get()
            ->sortBy(fn (Offering $offering) => sprintf(
                '%s|%s|%08d',
                mb_strtolower((string) ($offering->catalogCourse?->code ?? '')),
                mb_strtolower((string) ($offering->catalogCourse?->title ?? '')),
                (int) $offering->id,
            ))
            ->values();

        return view('aop.schedule.offerings.index', [
            'term' => $term,
            'offerings' => $offerings,
        ]);
    }

    public function create()
    {
        $term = $this->activeTermOrFail();

        return view('aop.schedule.offerings.create', [
            'term' => $term,
            'courses' => CatalogCourse::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    private function ensureScheduleUnlocked(Term $term): void
    {
        abort_if($term->schedule_locked, 403, 'Schedule is locked for the active term. Unlock it before making schedule changes.');
    }

    public function store(Request $request)
    {
        $term = $this->activeTermOrFail();
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'catalog_course_id' => ['required','integer','exists:catalog_courses,id'],
            'delivery_method' => ['nullable','string','max:80'],
            'notes' => ['nullable','string'],
            'prereq_override' => ['nullable','string'],
            'coreq_override' => ['nullable','string'],
        ]);

        $data['term_id'] = $term->id;

        $existing = Offering::where('term_id', $term->id)->where('catalog_course_id', $data['catalog_course_id'])->first();
        if ($existing) {
            if ($request->boolean('from_schedule_home')) {
                return redirect()->route('aop.schedule.home', ['focus_offering' => $existing->id])->with('status', 'Offering already exists for that course in this term.');
            }

            return redirect()->route('aop.schedule.offerings.index')->with('status', 'Offering already exists for that course in this term.');
        }

        $offering = Offering::create($data);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus_offering' => $offering->id])->with('status', 'Offering created.');
        }

        return redirect()->route('aop.schedule.offerings.index')->with('status', 'Offering created.');
    }
}
