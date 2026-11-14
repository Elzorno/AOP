<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\CatalogCourse;
use App\Models\Offering;
use App\Models\Term;
use Illuminate\Http\Request;

class OfferingController extends Controller
{
    public function index(Term $term)
    {
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

    public function create(Term $term)
    {
        return view('aop.schedule.offerings.create', [
            'term' => $term,
            'courses' => CatalogCourse::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, Term $term)
    {
        $data = $request->validate([
            'catalog_course_id' => ['required', 'integer', 'exists:catalog_courses,id'],
            'delivery_method' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'prereq_override' => ['nullable', 'string'],
            'coreq_override' => ['nullable', 'string'],
        ]);

        $data['term_id'] = $term->id;

        $existing = Offering::where('term_id', $term->id)->where('catalog_course_id', $data['catalog_course_id'])->first();
        if ($existing) {
            if ($request->boolean('from_schedule_home')) {
                return redirect()->route('aop.schedule.home', ['focus_offering' => $existing->id])->with('status', 'Offering already exists for that course in this term.');
            }

            return redirect()->route('aop.schedule.offerings.index', $term)->with('status', 'Offering already exists for that course in this term.');
        }

        $offering = Offering::create($data);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus_offering' => $offering->id])->with('status', 'Offering created.');
        }

        return redirect()->route('aop.schedule.offerings.index', $term)->with('status', 'Offering created.');
    }

    public function destroy(Request $request, Term $term, Offering $offering)
    {
        abort_if($offering->term_id !== $term->id, 404, 'Offering does not belong to this term.');

        if ($offering->sections()->exists()) {
            $count = $offering->sections()->count();
            $redirect = $request->boolean('from_schedule_home')
                ? redirect()->route('aop.schedule.home', ['focus_offering' => $offering->id])
                : redirect()->route('aop.schedule.offerings.index', $term);

            return $redirect->withErrors(['delete' => "Cannot remove this offering — it has {$count} section(s). Remove all sections first."]);
        }

        $offering->delete();

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home')->with('status', 'Offering removed.');
        }

        return redirect()->route('aop.schedule.offerings.index', $term)->with('status', 'Offering removed.');
    }
}
