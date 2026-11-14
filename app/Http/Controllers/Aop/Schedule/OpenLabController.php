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

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');

        return $term;
    }

    private function ensureOpenLabInActiveTerm(OpenLab $openLab): Term
    {
        $term = $this->activeTermOrFail();
        abort_if((int) $openLab->term_id !== (int) $term->id, 400, 'Open lab not in active term.');

        return $term;
    }

    public function index(LabTechWorkloadService $workloads)
    {
        $term = $this->activeTermOrFail();

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

    public function create()
    {
        $term = $this->activeTermOrFail();

        return view('aop.schedule.open-labs.create', [
            'term' => $term,
            'scheduleLocked' => false,
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'labTechs' => LabTech::query()->where('is_active', true)->orderBy('name')->get(),
            'weekDays' => self::ALLOWED_DAYS,
        ]);
    }

    public function store(Request $request, ScheduleConflictService $conflicts, LabTechWorkloadService $workloads)
    {
        $term = $this->activeTermOrFail();

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

        return redirect()->route('aop.schedule.openLabs.index')->with('status', 'Open lab added.');
    }

    public function edit(OpenLab $openLab)
    {
        $term = $this->ensureOpenLabInActiveTerm($openLab);
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

    public function update(Request $request, OpenLab $openLab, ScheduleConflictService $conflicts, LabTechWorkloadService $workloads)
    {
        $term = $this->ensureOpenLabInActiveTerm($openLab);

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

        return redirect()->route('aop.schedule.openLabs.index')->with('status', 'Open lab updated.');
    }

    public function destroy(OpenLab $openLab)
    {
        $term = $this->ensureOpenLabInActiveTerm($openLab);

        $openLab->delete();

        return redirect()->route('aop.schedule.openLabs.index')->with('status', 'Open lab removed.');
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
