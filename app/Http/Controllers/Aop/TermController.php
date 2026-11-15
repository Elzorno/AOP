<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\ScheduledEvent;
use App\Models\TermScheduleException;
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
            'code' => ['required', 'string', 'max:20', 'unique:terms,code'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'weeks_in_term' => ['required', 'integer', 'min:1', 'max:52'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:60'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'schedule_timezone' => ['nullable', 'timezone'],
        ]);

        $data['schedule_timezone'] = $data['schedule_timezone'] ?? 'UTC';

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
            'code' => ['required', 'string', 'max:20', 'unique:terms,code'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'weeks_in_term' => ['required', 'integer', 'min:1', 'max:52'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:60'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'schedule_timezone' => ['nullable', 'timezone'],
            'copy_instructor_assignments' => ['nullable', 'boolean'],
            'copy_office_hours' => ['nullable', 'boolean'],
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
            ! empty($data['copy_instructor_assignments']) ? 'Instructor assignments copied.' : 'Instructor assignments left blank.',
            $counts['office_hours'] > 0 ? $counts['office_hours'].' office hour blocks copied.' : 'Office hours not copied.',
            $counts['open_labs'] > 0 ? $counts['open_labs'].' open lab blocks copied.' : 'Open labs not copied.',
            'Locks, publications, and source change history were not copied; copied schedule records received new audit entries.',
        ];

        return redirect()->route('aop.terms.index')->with('status', implode(' ', $parts));
    }

    public function edit(Term $term)
    {
        return view('aop.terms.edit', [
            'term' => $term,
            'scheduleExceptions' => $term->scheduleExceptions()
                ->with('scheduledEvent')
                ->orderBy('occurs_on')
                ->get(),
            'scheduledEvents' => $term->scheduledEvents()->orderBy('display_name')->get(),
        ]);
    }

    public function update(Request $request, Term $term)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:terms,code,'.$term->id],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'weeks_in_term' => ['required', 'integer', 'min:1', 'max:52'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:60'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'schedule_timezone' => ['nullable', 'timezone'],
            'enforce_schedule_blocks' => ['nullable', 'boolean'],
        ]);

        $data['enforce_schedule_blocks'] = (bool) ($data['enforce_schedule_blocks'] ?? false);
        $data['schedule_timezone'] = $data['schedule_timezone'] ?? $term->schedule_timezone ?? 'UTC';

        $term->update($data);

        return redirect()->route('aop.terms.index')->with('status', 'Term updated.');
    }

    public function storeScheduleException(Request $request, Term $term)
    {
        $data = $request->validate([
            'exception_type' => ['required', 'in:holiday,cancellation,makeup'],
            'occurs_on' => ['required', 'date'],
            'scheduled_event_id' => ['nullable', 'integer', 'exists:scheduled_events,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $event = isset($data['scheduled_event_id'])
            ? ScheduledEvent::query()->find($data['scheduled_event_id'])
            : null;
        $requiresEvent = $data['exception_type'] !== TermScheduleException::TYPE_HOLIDAY;
        if (($requiresEvent && ! $event)
            || (! $requiresEvent && $event)
            || ($event && (int) $event->term_id !== (int) $term->id)) {
            return back()->withErrors([
                'scheduled_event_id' => $requiresEvent
                    ? 'Select an event from this term.'
                    : 'A term holiday must apply to the whole term.',
            ])->withInput();
        }
        if (($term->starts_on && $data['occurs_on'] < $term->starts_on->toDateString())
            || ($term->ends_on && $data['occurs_on'] > $term->ends_on->toDateString())) {
            return back()->withErrors(['occurs_on' => 'The exception date must fall within the term.'])->withInput();
        }

        TermScheduleException::query()->firstOrCreate([
            'term_id' => $term->id,
            'scheduled_event_id' => $event?->id,
            'occurs_on' => $data['occurs_on'],
            'exception_type' => $data['exception_type'],
        ], ['notes' => $data['notes'] ?? null]);

        return back()->with('status', 'Schedule exception added.');
    }

    public function destroyScheduleException(Term $term, TermScheduleException $scheduleException)
    {
        abort_unless((int) $scheduleException->term_id === (int) $term->id, 404);
        $scheduleException->delete();

        return back()->with('status', 'Schedule exception removed.');
    }

    public function setActive(Request $request)
    {
        $data = $request->validate([
            'term_id' => ['required', 'integer', 'exists:terms,id'],
        ]);

        Term::query()->update(['is_active' => false]);
        Term::where('id', $data['term_id'])->update(['is_active' => true]);

        return redirect()->route('aop.terms.index')->with('status', 'Active term updated.');
    }

    public function selectForSchedule(Request $request, Term $term)
    {
        $request->user()->forceFill(['selected_term_id' => $term->id])->save();

        return redirect()
            ->route('aop.schedule.offerings.index', $term)
            ->with('status', $term->code.' selected. Offering writes are now explicitly scoped to this term.');
    }

    public function makeDraft(Term $term, TermScheduleCloneService $cloneService)
    {
        $draft = $cloneService->cloneToDraft($term);

        return redirect()->route('aop.terms.index')->with('status', 'Draft '.$draft->code.' created from '.$term->code.'.');
    }

    public function publish(Term $term)
    {
        $term->update(['status' => 'published']);

        return redirect()->route('aop.terms.index')->with('status', 'Term '.$term->code.' has been published.');
    }
}
