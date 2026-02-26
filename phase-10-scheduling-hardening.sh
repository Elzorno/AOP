#!/usr/bin/env bash
set -euo pipefail

# Phase 10: Scheduling Hardening
# - Term-level schedule lock/unlock
# - Readiness dashboard (completeness + conflicts)
# - Enforce lock on schedule edits (offerings/sections/meeting blocks/office hours)

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

umask 022

write_file() {
  local rel="$1"
  local tmp
  tmp="${ROOT_DIR}/.${rel}.tmp"
  mkdir -p "$(dirname "${ROOT_DIR}/${rel}")"
  cat > "$tmp"
  mv "$tmp" "${ROOT_DIR}/${rel}"
  chmod 644 "${ROOT_DIR}/${rel}" || true
}

# Migration: add schedule lock columns to terms
write_file "database/migrations/2026_02_26_000012_add_schedule_lock_to_terms_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->boolean('schedule_locked')->default(false);
            $table->timestamp('schedule_locked_at')->nullable();
            $table->foreignId('schedule_locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_locked_by_user_id');
            $table->dropColumn(['schedule_locked', 'schedule_locked_at']);
        });
    }
};
PHP

# Model: Term
write_file "app/Models/Term.php" <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    protected $fillable = [
        'code','name','starts_on','ends_on','is_active',
        'weeks_in_term','slot_minutes','buffer_minutes',
        'allowed_hours_json',
        'schedule_locked','schedule_locked_at','schedule_locked_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'allowed_hours_json' => 'array',
        'schedule_locked' => 'boolean',
        'schedule_locked_at' => 'datetime',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class); }
    public function officeHourBlocks(): HasMany { return $this->hasMany(OfficeHourBlock::class); }

    public function scheduleLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'schedule_locked_by_user_id');
    }

    public function isScheduleLocked(): bool
    {
        return (bool)$this->schedule_locked;
    }
}
PHP

# Controllers
write_file "app/Http/Controllers/Aop/Schedule/ScheduleTermLockController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class ScheduleTermLockController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function lock(Request $request, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();

        if ($term->schedule_locked) {
            return redirect()->route('aop.schedule.home')->with('status', 'Schedule is already locked for the active term.');
        }

        $warningParts = $this->computeReadinessWarnings($term, $conflicts);

        $term->update([
            'schedule_locked' => true,
            'schedule_locked_at' => now(),
            'schedule_locked_by_user_id' => auth()->id(),
        ]);

        $msg = 'Schedule locked for the active term.';
        if (!empty($warningParts)) {
            $msg .= ' Warning: ' . implode(' | ', $warningParts);
        }

        return redirect()->route('aop.schedule.home')->with('status', $msg);
    }

    public function unlock(Request $request)
    {
        $term = $this->activeTermOrFail();

        if (!$term->schedule_locked) {
            return redirect()->route('aop.schedule.home')->with('status', 'Schedule is already unlocked for the active term.');
        }

        $term->update([
            'schedule_locked' => false,
            'schedule_locked_at' => null,
            'schedule_locked_by_user_id' => null,
        ]);

        return redirect()->route('aop.schedule.home')->with('status', 'Schedule unlocked for the active term.');
    }

    private function computeReadinessWarnings(Term $term, ScheduleConflictService $conflicts): array
    {
        $warnings = [];

        $sections = Section::query()
            ->with(['offering', 'meetingBlocks'])
            ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
            ->get();

        $missingInstructor = $sections->whereNull('instructor_id')->count();
        if ($missingInstructor > 0) {
            $warnings[] = "$missingInstructor section(s) missing instructor";
        }

        $missingMeetingBlocks = $sections->filter(fn($s) => $s->meetingBlocks->count() === 0)->count();
        if ($missingMeetingBlocks > 0) {
            $warnings[] = "$missingMeetingBlocks section(s) missing meeting blocks";
        }

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering'])
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->get();

        $missingRoom = $meetingBlocks->whereNull('room_id')->count();
        if ($missingRoom > 0) {
            $warnings[] = "$missingRoom meeting block(s) missing room";
        }

        $roomConflicts = $this->computeRoomConflictPairs($term);
        if ($roomConflicts > 0) {
            $warnings[] = "$roomConflicts room conflict(s)";
        }

        $instructorConflicts = $this->computeInstructorConflictPairs($term);
        if ($instructorConflicts > 0) {
            $warnings[] = "$instructorConflicts instructor conflict(s)";
        }

        return $warnings;
    }

    private function computeRoomConflictPairs(Term $term): int
    {
        $blocks = MeetingBlock::query()
            ->whereNotNull('room_id')
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->get();

        $byRoom = $blocks->groupBy('room_id');
        $pairs = 0;

        foreach ($byRoom as $roomId => $roomBlocks) {
            $list = $roomBlocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }
        }

        return $pairs;
    }

    private function computeInstructorConflictPairs(Term $term): int
    {
        $meetingBlocks = MeetingBlock::query()
            ->with('section')
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->whereHas('section', fn($q) => $q->whereNotNull('instructor_id'))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->get();

        $pairs = 0;

        $byInstructor = $meetingBlocks->groupBy(fn($mb) => (int)$mb->section->instructor_id);
        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        foreach ($byInstructor as $insId => $classBlocks) {
            $classList = $classBlocks->values();
            $n = $classList->count();

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }

            $officeList = ($officeByInstructor[$insId] ?? collect())->values();
            $m = $officeList->count();

            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }

            foreach ($classList as $c) {
                foreach ($officeList as $o) {
                    if (!ScheduleConflictService::dayOverlap($c->days_json ?? [], $o->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($c->starts_at, $c->ends_at, $o->starts_at, $o->ends_at)) continue;
                    $pairs++;
                }
            }
        }

        foreach ($officeByInstructor as $insId => $officeList) {
            if ($byInstructor->has($insId)) continue;
            $list = $officeList->values();
            $m = $list->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }
        }

        return $pairs;
    }
}
PHP

write_file "app/Http/Controllers/Aop/Schedule/ScheduleReadinessController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;

class ScheduleReadinessController extends Controller
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

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks.room'])
            ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('id','desc')
            ->get();

        $sectionsMissingInstructor = $sections->filter(fn($s) => !$s->instructor_id)->values();
        $sectionsMissingMeetingBlocks = $sections->filter(fn($s) => $s->meetingBlocks->count() === 0)->values();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('starts_at')
            ->get();

        $meetingBlocksMissingRoom = $meetingBlocks->filter(fn($mb) => !$mb->room_id)->values();

        $officeBlocks = OfficeHourBlock::query()
            ->with('instructor')
            ->where('term_id', $term->id)
            ->orderBy('starts_at')
            ->get();

        $roomConflicts = $this->computeRoomConflicts($meetingBlocks);
        $instructorConflicts = $this->computeInstructorConflicts($meetingBlocks, $officeBlocks);

        return view('aop.schedule.readiness.index', [
            'term' => $term,
            'sectionsMissingInstructor' => $sectionsMissingInstructor,
            'sectionsMissingMeetingBlocks' => $sectionsMissingMeetingBlocks,
            'meetingBlocksMissingRoom' => $meetingBlocksMissingRoom,
            'roomConflicts' => $roomConflicts,
            'instructorConflicts' => $instructorConflicts,
        ]);
    }

    private function computeRoomConflicts($meetingBlocks)
    {
        $conflicts = [];
        $byRoom = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');

        foreach ($byRoom as $roomId => $blocks) {
            $list = $blocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;

                    $conflicts[] = [
                        'room' => $a->room,
                        'a' => $a,
                        'b' => $b,
                    ];
                }
            }
        }

        return $conflicts;
    }

    private function computeInstructorConflicts($meetingBlocks, $officeBlocks)
    {
        $conflicts = [];

        $meetingByInstructor = $meetingBlocks
            ->filter(fn($mb) => (bool)$mb->section?->instructor_id)
            ->groupBy(fn($mb) => (int)$mb->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $allInstructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all()
        )));

        foreach ($allInstructorIds as $insId) {
            $classList = ($meetingByInstructor[$insId] ?? collect())->values();
            $officeList = ($officeByInstructor[$insId] ?? collect())->values();

            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $a->section->instructor,
                        'type' => 'CLASS vs CLASS',
                        'a_label' => ScheduleConflictService::formatMeetingBlockLabel($a),
                        'b_label' => ScheduleConflictService::formatMeetingBlockLabel($b),
                        'a_section_id' => $a->section_id,
                        'b_section_id' => $b->section_id,
                    ];
                }
            }

            $m = $officeList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $a->instructor,
                        'type' => 'OFFICE vs OFFICE',
                        'a_label' => ScheduleConflictService::formatOfficeHourLabel($a),
                        'b_label' => ScheduleConflictService::formatOfficeHourLabel($b),
                        'a_section_id' => null,
                        'b_section_id' => null,
                    ];
                }
            }

            foreach ($classList as $c) {
                foreach ($officeList as $o) {
                    if (!ScheduleConflictService::dayOverlap($c->days_json ?? [], $o->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($c->starts_at, $c->ends_at, $o->starts_at, $o->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $c->section->instructor,
                        'type' => 'CLASS vs OFFICE',
                        'a_label' => ScheduleConflictService::formatMeetingBlockLabel($c),
                        'b_label' => ScheduleConflictService::formatOfficeHourLabel($o),
                        'a_section_id' => $c->section_id,
                        'b_section_id' => null,
                    ];
                }
            }
        }

        return $conflicts;
    }
}
PHP

# Update existing schedule controllers/views/routes for lock enforcement + readiness link
write_file "app/Http/Controllers/Aop/Schedule/ScheduleHomeController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\SchedulePublication;
use App\Models\Term;

class ScheduleHomeController extends Controller
{
    public function index()
    {
        $term = Term::where('is_active', true)->with('scheduleLockedBy')->first();

        $latestPublication = null;
        if ($term) {
            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        return view('aop.schedule.index', [
            'term' => $term,
            'latestPublication' => $latestPublication,
        ]);
    }
}
PHP

write_file "app/Http/Controllers/Aop/Schedule/OfferingController.php" <<'PHP'
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

    private function ensureTermUnlocked(Term $term)
    {
        if ($term->schedule_locked) {
            abort(403, 'Schedule is locked for the active term. Unlock the term schedule to make changes.');
        }
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
        $this->ensureTermUnlocked($term);

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
PHP

write_file "app/Http/Controllers/Aop/Schedule/SectionController.php" <<'PHP'
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
PHP

write_file "app/Http/Controllers/Aop/Schedule/MeetingBlockController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class MeetingBlockController extends Controller
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

    private function ensureSectionInActiveTerm(Section $section): Term
    {
        $term = $this->activeTermOrFail();
        $section->load('offering');
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');
        return $term;
    }

    public function store(Request $request, Section $section, ScheduleConflictService $conflicts)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        $this->ensureTermUnlocked($term);

        $data = $request->validate([
            'type' => ['required','string'],
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        $days = array_values($data['days']);

        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at']);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            return back()->withErrors(['conflicts' => $msg])->withInput();
        }

        if (!empty($section->instructor_id)) {
            $insConf = $conflicts->instructorConflictsForMeetingBlock($term, (int)$section->instructor_id, $days, $data['starts_at'], $data['ends_at']);
            $messages = [];

            if ($insConf['meeting_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with classes: ' . $insConf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            }

            if ($insConf['office_hour_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with office hours: ' . $insConf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
            }

            if (!empty($messages)) {
                return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
            }
        }

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => $data['type'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block added.');
    }

    public function update(Request $request, Section $section, MeetingBlock $meetingBlock, ScheduleConflictService $conflicts)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        $this->ensureTermUnlocked($term);

        abort_if($meetingBlock->section_id !== $section->id, 400, 'Meeting block not in section.');

        $data = $request->validate([
            'type' => ['required','string'],
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        $days = array_values($data['days']);

        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at'], $meetingBlock->id);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            return back()->withErrors(['conflicts' => $msg])->withInput();
        }

        if (!empty($section->instructor_id)) {
            $insConf = $conflicts->instructorConflictsForMeetingBlock($term, (int)$section->instructor_id, $days, $data['starts_at'], $data['ends_at'], $meetingBlock->id);
            $messages = [];

            if ($insConf['meeting_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with classes: ' . $insConf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            }

            if ($insConf['office_hour_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with office hours: ' . $insConf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
            }

            if (!empty($messages)) {
                return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
            }
        }

        $meetingBlock->update([
            'type' => $data['type'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block updated.');
    }
}
PHP

write_file "app/Http/Controllers/Aop/Schedule/OfficeHoursController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\InstructorTermLock;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class OfficeHoursController extends Controller
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

    private function lockFor(Term $term, Instructor $instructor): InstructorTermLock
    {
        return InstructorTermLock::for($term, $instructor);
    }

    public function index()
    {
        $term = Term::where('is_active', true)->first();

        return view('aop.schedule.office-hours.index', [
            'term' => $term,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

        abort_if(!$instructor->is_active, 404, 'Instructor not found.');

        $lock = $this->lockFor($term, $instructor);

        $blocks = OfficeHourBlock::where('term_id', $term->id)
            ->where('instructor_id', $instructor->id)
            ->orderBy('starts_at')
            ->get();

        return view('aop.schedule.office-hours.show', [
            'term' => $term,
            'instructor' => $instructor,
            'lock' => $lock,
            'blocks' => $blocks,
            'days' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
        ]);
    }

    private function ensureUnlocked(Term $term, Instructor $instructor): void
    {
        $lock = $this->lockFor($term, $instructor);
        abort_if($lock->office_hours_locked, 403, 'Office hours are locked for this instructor in the active term.');
    }

    public function store(Request $request, Instructor $instructor, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();
        $this->ensureTermUnlocked($term);
        $this->ensureUnlocked($term, $instructor);

        $data = $request->validate([
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        $days = array_values($data['days']);

        $conf = $conflicts->instructorConflictsForOfficeHourBlock($term, $instructor->id, $days, $data['starts_at'], $data['ends_at']);

        $messages = [];

        if ($conf['office_hour_blocks']->count() > 0) {
            $messages[] = 'Conflicts with existing office hours: ' . $conf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
        }

        if ($conf['meeting_blocks']->count() > 0) {
            $messages[] = 'Conflicts with class meeting blocks: ' . $conf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
        }

        if (!empty($messages)) {
            return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
        }

        OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block added.');
    }

    public function update(Request $request, Instructor $instructor, OfficeHourBlock $officeHourBlock, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();
        $this->ensureTermUnlocked($term);
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in active term/instructor.');

        $data = $request->validate([
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        $days = array_values($data['days']);

        $conf = $conflicts->instructorConflictsForOfficeHourBlock($term, $instructor->id, $days, $data['starts_at'], $data['ends_at'], $officeHourBlock->id);

        $messages = [];

        if ($conf['office_hour_blocks']->count() > 0) {
            $messages[] = 'Conflicts with existing office hours: ' . $conf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
        }

        if ($conf['meeting_blocks']->count() > 0) {
            $messages[] = 'Conflicts with class meeting blocks: ' . $conf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
        }

        if (!empty($messages)) {
            return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
        }

        $officeHourBlock->update([
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block updated.');
    }

    public function destroy(Request $request, Instructor $instructor, OfficeHourBlock $officeHourBlock)
    {
        $term = $this->activeTermOrFail();
        $this->ensureTermUnlocked($term);
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in active term/instructor.');

        $officeHourBlock->delete();

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block deleted.');
    }

    public function lock(Request $request, Instructor $instructor)
    {
        $term = $this->activeTermOrFail();
        $this->ensureTermUnlocked($term);

        $lock = $this->lockFor($term, $instructor);
        $lock->update([
            'office_hours_locked' => true,
            'office_hours_locked_at' => now(),
            'office_hours_locked_by_user_id' => auth()->id(),
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours locked for this instructor (active term).');
    }

    public function unlock(Request $request, Instructor $instructor)
    {
        $term = $this->activeTermOrFail();
        $this->ensureTermUnlocked($term);

        $lock = $this->lockFor($term, $instructor);
        $lock->update([
            'office_hours_locked' => false,
            'office_hours_locked_at' => null,
            'office_hours_locked_by_user_id' => null,
        ]);

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours unlocked for this instructor (active term).');
    }
}
PHP

# Views
write_file "resources/views/aop/schedule/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Schedule</h1>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before scheduling.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>

      <div style="margin-top:8px;">
        @if($term->schedule_locked)
          <p class="muted">Schedule lock: <span class="badge">Locked</span>
            @if($term->schedule_locked_at)
              {{ $term->schedule_locked_at->format('Y-m-d H:i') }}
            @endif
            @if($term->scheduleLockedBy)
              by {{ $term->scheduleLockedBy->name }}
            @endif
          </p>
        @else
          <p class="muted">Schedule lock: <span class="badge">Unlocked</span></p>
        @endif

        <div class="actions" style="margin-top:8px; flex-wrap:wrap; gap:8px;">
          @if($term->schedule_locked)
            <form method="POST" action="{{ route('aop.schedule.term.unlock') }}" style="display:inline;">
              @csrf
              <button class="btn secondary" type="submit">Unlock Schedule</button>
            </form>
          @else
            <form method="POST" action="{{ route('aop.schedule.term.lock') }}" style="display:inline;">
              @csrf
              <button class="btn" type="submit">Lock Schedule</button>
            </form>
          @endif

          <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness Dashboard</a>
        </div>
      </div>

      @if($latestPublication)
        <p class="muted" style="margin-top:10px;">Published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
      @else
        <p class="muted" style="margin-top:10px;">Published: <span class="badge">None</span></p>
      @endif

      <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
        <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
      </div>

      @if($term->schedule_locked)
        <p class="muted" style="margin-top:10px;">Note: schedule edits (sections, meeting blocks, office hours) are disabled while locked.</p>
      @endif
    @endif
  </div>
</x-aop-layout>
BLADE

write_file "resources/views/aop/schedule/readiness/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Schedule Readiness</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Readiness</h1>
      <p class="muted" style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      @if($term->schedule_locked)
        <p class="muted">Schedule lock: <span class="badge">Locked</span></p>
      @else
        <p class="muted">Schedule lock: <span class="badge">Unlocked</span></p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
    </div>
  </div>

  <div class="card">
    <h2>Completeness</h2>

    <table>
      <thead>
        <tr>
          <th style="width:260px;">Check</th>
          <th style="width:120px;">Count</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Sections missing instructor</td>
          <td><span class="badge">{{ $sectionsMissingInstructor->count() }}</span></td>
          <td class="muted">Assign an instructor on the Section edit page.</td>
        </tr>
        <tr>
          <td>Sections missing meeting blocks</td>
          <td><span class="badge">{{ $sectionsMissingMeetingBlocks->count() }}</span></td>
          <td class="muted">Add at least one meeting block per section.</td>
        </tr>
        <tr>
          <td>Meeting blocks missing room</td>
          <td><span class="badge">{{ $meetingBlocksMissingRoom->count() }}</span></td>
          <td class="muted">Rooms are required for in-person/hybrid meeting blocks.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Missing Instructor</h2>
    @if($sectionsMissingInstructor->count() === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Modality</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sectionsMissingInstructor as $s)
            <tr>
              <td>{{ $s->offering->catalogCourse->code }} — {{ $s->offering->catalogCourse->title }}</td>
              <td>{{ $s->section_code }}</td>
              <td><span class="badge">{{ $s->modality->value }}</span></td>
              <td><a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $s) }}">Edit Section</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Missing Meeting Blocks</h2>
    @if($sectionsMissingMeetingBlocks->count() === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Instructor</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sectionsMissingMeetingBlocks as $s)
            <tr>
              <td>{{ $s->offering->catalogCourse->code }} — {{ $s->offering->catalogCourse->title }}</td>
              <td>{{ $s->section_code }}</td>
              <td>{{ $s->instructor?->name ?? '—' }}</td>
              <td><a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $s) }}">Edit Section</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Meeting Blocks Missing Room</h2>
    @if($meetingBlocksMissingRoom->count() === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Days/Times</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach($meetingBlocksMissingRoom as $mb)
            <tr>
              <td>{{ $mb->section->offering->catalogCourse->code }}</td>
              <td>{{ $mb->section->section_code }}</td>
              <td class="muted">{{ implode(',', $mb->days_json ?? []) }} {{ substr($mb->starts_at,0,5) }}-{{ substr($mb->ends_at,0,5) }}</td>
              <td><a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $mb->section) }}">Edit Section</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Room Conflicts (Class vs Class)</h2>
    @if(count($roomConflicts) === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Room</th>
            <th>Conflict A</th>
            <th>Conflict B</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($roomConflicts as $c)
            <tr>
              <td>{{ $c['room']?->name ?? '—' }}</td>
              <td class="muted">{{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($c['a']) }}</td>
              <td class="muted">{{ \App\Services\ScheduleConflictService::formatMeetingBlockLabel($c['b']) }}</td>
              <td>
                <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['a']->section_id) }}">Edit A</a>
                <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['b']->section_id) }}">Edit B</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Instructor Conflicts</h2>
    @if(count($instructorConflicts) === 0)
      <p class="muted">None.</p>
    @else
      <table>
        <thead>
          <tr>
            <th>Instructor</th>
            <th>Type</th>
            <th>Conflict A</th>
            <th>Conflict B</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($instructorConflicts as $c)
            <tr>
              <td>{{ $c['instructor']?->name ?? '—' }}</td>
              <td><span class="badge">{{ $c['type'] }}</span></td>
              <td class="muted">{{ $c['a_label'] }}</td>
              <td class="muted">{{ $c['b_label'] }}</td>
              <td>
                @if($c['a_section_id'])
                  <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['a_section_id']) }}">Edit A</a>
                @endif
                @if($c['b_section_id'])
                  <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $c['b_section_id']) }}">Edit B</a>
                @endif
                @if($c['instructor'])
                  <a class="btn secondary" href="{{ route('aop.schedule.officeHours.show', $c['instructor']->id) }}">Office Hours</a>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
BLADE

# Routes
write_file "routes/web.php" <<'PHP'
<?php

use App\Http\Controllers\Aop\CatalogCourseController;
use App\Http\Controllers\Aop\DashboardController;
use App\Http\Controllers\Aop\InstructorController;
use App\Http\Controllers\Aop\RoomController;
use App\Http\Controllers\Aop\TermController;
use App\Http\Controllers\Aop\Schedule\MeetingBlockController;
use App\Http\Controllers\Aop\Schedule\OfficeHoursController;
use App\Http\Controllers\Aop\Schedule\OfferingController;
use App\Http\Controllers\Aop\Schedule\ScheduleGridController;
use App\Http\Controllers\Aop\Schedule\ScheduleHomeController;
use App\Http\Controllers\Aop\Schedule\SchedulePublishController;
use App\Http\Controllers\Aop\Schedule\ScheduleReadinessController;
use App\Http\Controllers\Aop\Schedule\ScheduleReportsController;
use App\Http\Controllers\Aop\Schedule\ScheduleTermLockController;
use App\Http\Controllers\Aop\Schedule\SectionController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('/aop')->name('aop.')->middleware(['admin'])->group(function () {
        // Terms
        Route::get('/terms', [TermController::class, 'index'])->name('terms.index');
        Route::get('/terms/create', [TermController::class, 'create'])->name('terms.create');
        Route::post('/terms', [TermController::class, 'store'])->name('terms.store');
        Route::get('/terms/{term}/edit', [TermController::class, 'edit'])->name('terms.edit');
        Route::put('/terms/{term}', [TermController::class, 'update'])->name('terms.update');
        Route::post('/terms/active', [TermController::class, 'setActive'])->name('terms.setActive');

        // Instructors
        Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
        Route::get('/instructors/create', [InstructorController::class, 'create'])->name('instructors.create');
        Route::post('/instructors', [InstructorController::class, 'store'])->name('instructors.store');
        Route::get('/instructors/{instructor}/edit', [InstructorController::class, 'edit'])->name('instructors.edit');
        Route::put('/instructors/{instructor}', [InstructorController::class, 'update'])->name('instructors.update');

        // Rooms
        Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/create', [RoomController::class, 'create'])->name('rooms.create');
        Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::get('/rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');

        // Catalog
        Route::get('/catalog', [CatalogCourseController::class, 'index'])->name('catalog.index');
        Route::get('/catalog/create', [CatalogCourseController::class, 'create'])->name('catalog.create');
        Route::post('/catalog', [CatalogCourseController::class, 'store'])->name('catalog.store');
        Route::get('/catalog/{catalogCourse}/edit', [CatalogCourseController::class, 'edit'])->name('catalog.edit');
        Route::put('/catalog/{catalogCourse}', [CatalogCourseController::class, 'update'])->name('catalog.update');

        // Schedule (active term)
        Route::get('/schedule', [ScheduleHomeController::class, 'index'])->name('schedule.home');

        // Term schedule lock/unlock
        Route::post('/schedule/term/lock', [ScheduleTermLockController::class, 'lock'])->name('schedule.term.lock');
        Route::post('/schedule/term/unlock', [ScheduleTermLockController::class, 'unlock'])->name('schedule.term.unlock');

        // Readiness dashboard
        Route::get('/schedule/readiness', [ScheduleReadinessController::class, 'index'])->name('schedule.readiness.index');

        // Offerings
        Route::get('/schedule/offerings', [OfferingController::class, 'index'])->name('schedule.offerings.index');
        Route::get('/schedule/offerings/create', [OfferingController::class, 'create'])->name('schedule.offerings.create');
        Route::post('/schedule/offerings', [OfferingController::class, 'store'])->name('schedule.offerings.store');

        // Sections
        Route::get('/schedule/sections', [SectionController::class, 'index'])->name('schedule.sections.index');
        Route::get('/schedule/sections/create', [SectionController::class, 'create'])->name('schedule.sections.create');
        Route::post('/schedule/sections', [SectionController::class, 'store'])->name('schedule.sections.store');
        Route::get('/schedule/sections/{section}/edit', [SectionController::class, 'edit'])->name('schedule.sections.edit');
        Route::put('/schedule/sections/{section}', [SectionController::class, 'update'])->name('schedule.sections.update');

        // Meeting blocks nested under section
        Route::post('/schedule/sections/{section}/meeting-blocks', [MeetingBlockController::class, 'store'])->name('schedule.meetingBlocks.store');
        Route::put('/schedule/sections/{section}/meeting-blocks/{meetingBlock}', [MeetingBlockController::class, 'update'])->name('schedule.meetingBlocks.update');

        // Office Hours (active term)
        Route::get('/schedule/office-hours', [OfficeHoursController::class, 'index'])->name('schedule.officeHours.index');
        Route::get('/schedule/office-hours/{instructor}', [OfficeHoursController::class, 'show'])->name('schedule.officeHours.show');
        Route::post('/schedule/office-hours/{instructor}/blocks', [OfficeHoursController::class, 'store'])->name('schedule.officeHours.blocks.store');
        Route::put('/schedule/office-hours/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'update'])->name('schedule.officeHours.blocks.update');
        Route::delete('/schedule/office-hours/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'destroy'])->name('schedule.officeHours.blocks.destroy');
        Route::post('/schedule/office-hours/{instructor}/lock', [OfficeHoursController::class, 'lock'])->name('schedule.officeHours.lock');
        Route::post('/schedule/office-hours/{instructor}/unlock', [OfficeHoursController::class, 'unlock'])->name('schedule.officeHours.unlock');

        // Schedule Grids (active term)
        Route::get('/schedule/grids', [ScheduleGridController::class, 'index'])->name('schedule.grids.index');
        Route::get('/schedule/grids/instructors/{instructor}', [ScheduleGridController::class, 'instructor'])->name('schedule.grids.instructor');
        Route::get('/schedule/grids/rooms/{room}', [ScheduleGridController::class, 'room'])->name('schedule.grids.room');

        // Schedule Reports (active term)
        Route::get('/schedule/reports', [ScheduleReportsController::class, 'index'])->name('schedule.reports.index');
        Route::get('/schedule/reports/export/term', [ScheduleReportsController::class, 'exportTerm'])->name('schedule.reports.exportTerm');
        Route::get('/schedule/reports/export/instructors/{instructor}', [ScheduleReportsController::class, 'exportInstructor'])->name('schedule.reports.exportInstructor');
        Route::get('/schedule/reports/export/rooms/{room}', [ScheduleReportsController::class, 'exportRoom'])->name('schedule.reports.exportRoom');

        // Publish Snapshots (active term)
        Route::get('/schedule/publish', [SchedulePublishController::class, 'index'])->name('schedule.publish.index');
        Route::post('/schedule/publish', [SchedulePublishController::class, 'store'])->name('schedule.publish.store');
        Route::get('/schedule/publish/{publication}/download/term', [SchedulePublishController::class, 'downloadTerm'])->name('schedule.publish.downloadTerm');
        Route::get('/schedule/publish/{publication}/download/instructors', [SchedulePublishController::class, 'downloadInstructorsZip'])->name('schedule.publish.downloadInstructorsZip');
        Route::get('/schedule/publish/{publication}/download/rooms', [SchedulePublishController::class, 'downloadRoomsZip'])->name('schedule.publish.downloadRoomsZip');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Public read-only published schedule (Phase 9)
Route::get('/p/{termCode}/{version?}/{token}', [\App\Http\Controllers\Public\SchedulePublicController::class, 'show'])
    ->whereNumber('version')
    ->name('public.schedule.show');

Route::get('/p/{termCode}/{version}/{token}/download/term', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadTerm'])
    ->whereNumber('version')
    ->name('public.schedule.download.term');

Route::get('/p/{termCode}/{version}/{token}/download/instructors', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadInstructorsZip'])
    ->whereNumber('version')
    ->name('public.schedule.download.instructors');

Route::get('/p/{termCode}/{version}/{token}/download/rooms', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadRoomsZip'])
    ->whereNumber('version')
    ->name('public.schedule.download.rooms');
PHP

echo "OK: Phase 10 applied (schedule lock + readiness dashboard)."
