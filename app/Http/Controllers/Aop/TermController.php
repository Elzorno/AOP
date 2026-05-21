<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Services\TermScheduleCloneService;
use Illuminate\Http\Request;

class TermController extends Controller
{
    public function index()
    {
        return view('aop.terms.index', [
            'terms' => Term::orderByDesc('created_at')->get(),
            'active' => Term::where('is_active', true)->first(),
        ]);
    }

    public function create()
    {
        return view('aop.terms.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required','string','max:20','unique:terms,code'],
            'name' => ['required','string','max:255'],
            'starts_on' => ['nullable','date'],
            'ends_on' => ['nullable','date'],
            'weeks_in_term' => ['required','integer','min:1','max:52'],
            'slot_minutes' => ['required','integer','min:5','max:60'],
            'buffer_minutes' => ['required','integer','min:0','max:60'],
        ]);

        Term::create($data);

        return redirect()->route('aop.terms.index')->with('status', 'Term created.');
    }

    public function cloneCreate(Term $sourceTerm)
    {
        return view('aop.terms.clone', [
            'sourceTerm' => $sourceTerm,
        ]);
    }

    public function cloneStore(Request $request, Term $sourceTerm, TermScheduleCloneService $cloneService)
    {
        $data = $request->validate([
            'code' => ['required','string','max:20','unique:terms,code'],
            'name' => ['required','string','max:255'],
            'starts_on' => ['nullable','date'],
            'ends_on' => ['nullable','date'],
            'weeks_in_term' => ['required','integer','min:1','max:52'],
            'slot_minutes' => ['required','integer','min:5','max:60'],
            'buffer_minutes' => ['required','integer','min:0','max:60'],
            'copy_instructor_assignments' => ['nullable','boolean'],
            'copy_office_hours' => ['nullable','boolean'],
        ]);

        $result = $cloneService->cloneIntoFreshTerm(
            $sourceTerm,
            $data,
            (bool) ($data['copy_instructor_assignments'] ?? false),
            (bool) ($data['copy_office_hours'] ?? false)
        );

        $targetTerm = $result['term'];
        $counts = $result['counts'];

        $parts = [
            'Term '.$targetTerm->code.' created from '.$sourceTerm->code.'.',
            'Copied '.$counts['offerings'].' offerings, '.$counts['sections'].' sections, and '.$counts['meeting_blocks'].' meeting blocks.',
            !empty($data['copy_instructor_assignments']) ? 'Instructor assignments copied.' : 'Instructor assignments left blank.',
            $counts['office_hours'] > 0 ? $counts['office_hours'].' office hour blocks copied.' : 'Office hours not copied.',
            'Locks, publications, syllabi, and render history were not copied.',
        ];

        return redirect()->route('aop.terms.index')->with('status', implode(' ', $parts));
    }

    public function edit(Term $term)
    {
        return view('aop.terms.edit', ['term' => $term]);
    }

    public function update(Request $request, Term $term)
    {
        $data = $request->validate([
            'code' => ['required','string','max:20','unique:terms,code,'.$term->id],
            'name' => ['required','string','max:255'],
            'starts_on' => ['nullable','date'],
            'ends_on' => ['nullable','date'],
            'weeks_in_term' => ['required','integer','min:1','max:52'],
            'slot_minutes' => ['required','integer','min:5','max:60'],
            'buffer_minutes' => ['required','integer','min:0','max:60'],
            'enforce_schedule_blocks' => ['nullable','boolean'],
        ]);

        $data['enforce_schedule_blocks'] = (bool) ($data['enforce_schedule_blocks'] ?? false);

        $term->update($data);

        return redirect()->route('aop.terms.index')->with('status', 'Term updated.');
    }

    public function setActive(Request $request)
    {
        $data = $request->validate([
            'term_id' => ['required','integer','exists:terms,id'],
        ]);

        Term::query()->update(['is_active' => false]);
        Term::where('id', $data['term_id'])->update(['is_active' => true]);

        return redirect()->route('aop.terms.index')->with('status', 'Active term updated.');
    }

    public function makeDraft(Term $term)
    {
        $draft = $term->cloneToDraft();
        return redirect()->route('aop.terms.index')->with('status', 'Draft ' . $draft->code . ' created from ' . $term->code . '.');
    }

    public function publish(Term $term)
    {
        $term->update(['status' => 'published']);
        return redirect()->route('aop.terms.index')->with('status', 'Term ' . $term->code . ' has been published.');
    }
}
