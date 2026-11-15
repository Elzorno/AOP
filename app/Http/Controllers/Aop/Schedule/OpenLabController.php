<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\LabTech;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Term;
use App\Services\LabTechWorkloadService;
use App\Services\OpenLabMutationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpenLabController extends Controller
{
    private const ALLOWED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private function ensureOpenLabInTerm(Term $term, OpenLab $openLab): void
    {
        abort_if((int) $openLab->term_id !== (int) $term->id, 404, 'Open lab not in this term.');
    }

    public function index(Term $term, LabTechWorkloadService $workloads)
    {
        return view('aop.schedule.open-labs.index', [
            'term' => $term,
            'scheduleLocked' => false,
            'openLabs' => OpenLab::query()
                ->with(['room', 'labTech'])
                ->where('term_id', $term->id)
                ->orderBy('starts_at')
                ->orderBy('name')
                ->get(),
            'workloadRows' => $workloads->summariesForTerm($term, activeOnly: true),
        ]);
    }

    public function create(Term $term)
    {
        return view('aop.schedule.open-labs.create', [
            'term' => $term,
            'scheduleLocked' => false,
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'labTechs' => LabTech::query()->where('is_active', true)->orderBy('name')->get(),
            'weekDays' => self::ALLOWED_DAYS,
        ]);
    }

    public function store(Request $request, Term $term, OpenLabMutationService $mutations)
    {
        $data = $this->validatePayload($request);
        $mutations->create($term, $data);

        return redirect()->route('aop.schedule.openLabs.index', $term)->with('status', 'Open lab added.');
    }

    public function edit(Term $term, OpenLab $openLab)
    {
        $this->ensureOpenLabInTerm($term, $openLab);
        $openLab->load(['room', 'labTech']);

        return view('aop.schedule.open-labs.edit', [
            'term' => $term,
            'openLab' => $openLab,
            'scheduleLocked' => false,
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'labTechs' => LabTech::query()->where('is_active', true)->orderBy('name')->get(),
            'weekDays' => self::ALLOWED_DAYS,
        ]);
    }

    public function update(Request $request, Term $term, OpenLab $openLab, OpenLabMutationService $mutations)
    {
        $this->ensureOpenLabInTerm($term, $openLab);

        $data = $this->validatePayload($request);
        $mutations->update($term, $openLab, $data);

        return redirect()->route('aop.schedule.openLabs.index', $term)->with('status', 'Open lab updated.');
    }

    public function destroy(Term $term, OpenLab $openLab, OpenLabMutationService $mutations)
    {
        $this->ensureOpenLabInTerm($term, $openLab);

        $mutations->delete($term, $openLab);

        return redirect()->route('aop.schedule.openLabs.index', $term)->with('status', 'Open lab removed.');
    }

    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'room_id' => [
                'required',
                'integer',
                Rule::exists('rooms', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'lab_tech_id' => [
                'required',
                'integer',
                Rule::exists('lab_techs', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $data;
    }
}
