<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\Offering;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    private function ensureTermUnlocked(Term $term)
    {
        if ($term->schedule_locked) {
            abort(403, 'Schedule is locked for the active term. Unlock the term schedule to make changes.');
        }
    }

    public function index()
    {
        $term = $this->activeTermOrFail();

        $sections = Section::query()
            ->with(['offering.catalogCourse','instructor','meetingBlocks'])
            ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('id','desc')
            ->get();

        return view('aop.schedule.sections.index', [
            'term' => $term,
            'sections' => $sections,
        ]);
    }

    public function create()
    {
        $term = $this->activeTermOrFail();

        $offerings = Offering::with('catalogCourse')
            ->where('term_id', $term->id)
            ->orderBy('id','desc')
            ->get();

        return view('aop.schedule.sections.create', [
            'term' => $term,
            'offerings' => $offerings,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $term = $this->activeTermOrFail();
        $this->ensureTermUnlocked($term);

        $data = $request->validate([
            'offering_id' => ['required','integer','exists:offerings,id'],
            'section_code' => ['required','string','max:20'],
            'instructor_id' => ['nullable','integer','exists:instructors,id'],
            'modality' => ['required','string'],
            'notes' => ['nullable','string'],
        ]);

        $offering = Offering::where('id', $data['offering_id'])->where('term_id', $term->id)->first();
        abort_if(!$offering, 400, 'Offering not in active term.');

        Section::create($data);

        return redirect()->route('aop.schedule.sections.index')->with('status', 'Section created.');
    }

    public function edit(Section $section)
    {
        $term = $this->activeTermOrFail();
        $section->load(['offering.catalogCourse','instructor','meetingBlocks.room']);
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');

        return view('aop.schedule.sections.edit', [
            'term' => $term,
            'section' => $section,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
        ]);
    }

    public function update(Request $request, Section $section)
    {
        $term = $this->activeTermOrFail();
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');
        $this->ensureTermUnlocked($term);

        $data = $request->validate([
            'section_code' => ['required','string','max:20'],
            'instructor_id' => ['nullable','integer','exists:instructors,id'],
            'modality' => ['required','string'],
            'notes' => ['nullable','string'],
        ]);

        $section->update($data);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Section updated.');
    }
}
