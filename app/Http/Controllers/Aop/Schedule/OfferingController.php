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
            ->where('term_id', $term->id)
            ->orderBy('id', 'desc')
            ->get();

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

    public function store(Request $request)
    {
        $term = $this->activeTermOrFail();

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
            return redirect()->route('aop.schedule.offerings.index')->with('status', 'Offering already exists for that course in this term.');
        }

        Offering::create($data);

        return redirect()->route('aop.schedule.offerings.index')->with('status', 'Offering created.');
    }
}
