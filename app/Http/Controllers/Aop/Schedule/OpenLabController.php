<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\LabTech;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Term;
use App\Services\LabTechWorkloadService;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request, Term $term, ScheduleConflictService $conflicts, LabTechWorkloadService $workloads)
    {
        $data = $this->validatePayload($request);
        $days = array_values(array_unique($data['days']));

        $this->assertNoOpenLabConflicts($term, $data, $days, $conflicts, $workloads);

        OpenLab::create([
            'term_id' => $term->id,
            'name' => $data['name'],
            'room_id' => $data['room_id'],
            'lab_tech_id' => $data['lab_tech_id'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]);

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

    public function update(Request $request, Term $term, OpenLab $openLab, ScheduleConflictService $conflicts, LabTechWorkloadService $workloads)
    {
        $this->ensureOpenLabInTerm($term, $openLab);

        $data = $this->validatePayload($request);
        $days = array_values(array_unique($data['days']));

        $this->assertNoOpenLabConflicts($term, $data, $days, $conflicts, $workloads, $openLab->id);

        $openLab->update([
            'name' => $data['name'],
            'room_id' => $data['room_id'],
            'lab_tech_id' => $data['lab_tech_id'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.openLabs.index', $term)->with('status', 'Open lab updated.');
    }

    public function destroy(Term $term, OpenLab $openLab)
    {
        $this->ensureOpenLabInTerm($term, $openLab);

        $openLab->delete();

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

        if ($data['ends_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'End time must be after start time.',
            ]);
        }

        return $data;
    }

    private function assertNoOpenLabConflicts(
        Term $term,
        array $data,
        array $days,
        ScheduleConflictService $conflicts,
        LabTechWorkloadService $workloads,
        ?int $ignoreOpenLabId = null,
    ): void {
        $roomConflicts = $conflicts->roomConflictsForOpenLab(
            $term,
            (int) $data['room_id'],
            $days,
            $data['starts_at'],
            $data['ends_at'],
            $ignoreOpenLabId,
        );

        $roomConflictLabels = ScheduleConflictService::formatRoomConflictMessages($roomConflicts);
        if ($roomConflictLabels !== []) {
            throw ValidationException::withMessages([
                'conflicts' => 'Room conflict with: '.implode('; ', $roomConflictLabels),
            ]);
        }

        $labTechConflicts = $conflicts->labTechConflictsForOpenLab(
            $term,
            (int) $data['lab_tech_id'],
            $days,
            $data['starts_at'],
            $data['ends_at'],
            $ignoreOpenLabId,
        );

        if ($labTechConflicts->count() > 0) {
            throw ValidationException::withMessages([
                'conflicts' => 'Lab tech conflict with: '.$labTechConflicts
                    ->map(fn (OpenLab $openLab) => ScheduleConflictService::formatOpenLabLabel($openLab))
                    ->implode('; '),
            ]);
        }

        $labTech = LabTech::findOrFail((int) $data['lab_tech_id']);
        $existingMinutes = $workloads->assignedMinutesForLabTech($term, $labTech, $ignoreOpenLabId);
        $newMinutes = ScheduleConflictService::weeklyMinutes($days, $data['starts_at'], $data['ends_at']);
        $projectedMinutes = $existingMinutes + $newMinutes;
        $limitMinutes = (int) round(((float) $labTech->weekly_hour_limit) * 60);

        if ($projectedMinutes > $limitMinutes) {
            throw ValidationException::withMessages([
                'lab_tech_id' => sprintf(
                    '%s would be scheduled for %.2f weekly hours, above the HR limit of %.2f.',
                    $labTech->name,
                    $projectedMinutes / 60,
                    (float) $labTech->weekly_hour_limit,
                ),
            ]);
        }
    }
}
