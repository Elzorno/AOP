<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\Offering;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');

        return $term;
    }

    private function ensureScheduleUnlocked(Term $term): void
    {
        abort_if($term->schedule_locked, 403, 'Schedule is locked for the active term. Unlock it before making schedule changes.');
    }

    public function index()
    {
        $term = $this->activeTermOrFail();

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->orderBy('id', 'desc')
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
            ->orderBy('id', 'desc')
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
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'offering_id' => ['required', 'integer', 'exists:offerings,id'],
            'section_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('sections', 'section_code')
                    ->where(fn ($q) => $q->where('offering_id', $request->integer('offering_id'))),
            ],
            'instructor_id' => ['nullable', 'integer', 'exists:instructors,id'],
            'modality' => ['required', Rule::enum(SectionModality::class)],
            'notes' => ['nullable', 'string'],
        ]);

        $offering = Offering::where('id', $data['offering_id'])
            ->where('term_id', $term->id)
            ->first();
        abort_if(!$offering, 400, 'Offering not in active term.');

        Section::create($data);

        return redirect()->route('aop.schedule.sections.index')->with('status', 'Section created.');
    }

    public function edit(Section $section)
    {
        $term = $this->activeTermOrFail();
        $section->load(['offering.catalogCourse', 'instructor', 'meetingBlocks.room']);
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
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'section_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('sections', 'section_code')
                    ->where(fn ($q) => $q->where('offering_id', $section->offering_id))
                    ->ignore($section->id),
            ],
            'instructor_id' => ['nullable', 'integer', 'exists:instructors,id'],
            'modality' => ['required', Rule::enum(SectionModality::class)],
            'notes' => ['nullable', 'string'],
        ]);

        $section->update($data);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Section updated.');
    }
}
