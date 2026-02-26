#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

write_file () {
  local rel="$1"
  local tmp
  tmp="$(mktemp)"
  cat > "$tmp"
  mkdir -p "$(dirname "$ROOT_DIR/$rel")"
  mv "$tmp" "$ROOT_DIR/$rel"
  echo "WROTE: $rel"
}

# --- Phase 5: Office Hours + Lock/Unlock + Conflict Detection ---

write_file "database/migrations/2026_02_25_000009_create_instructor_term_locks_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instructor_term_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();

            $table->boolean('office_hours_locked')->default(false);
            $table->timestamp('office_hours_locked_at')->nullable();
            $table->foreignId('office_hours_locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['term_id', 'instructor_id']);
            $table->index(['term_id', 'office_hours_locked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_term_locks');
    }
};

PHP

write_file "app/Models/InstructorTermLock.php" <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorTermLock extends Model
{
    protected $fillable = [
        'term_id',
        'instructor_id',
        'office_hours_locked',
        'office_hours_locked_at',
        'office_hours_locked_by_user_id',
    ];

    protected $casts = [
        'office_hours_locked' => 'boolean',
        'office_hours_locked_at' => 'datetime',
    ];

    public function term(): BelongsTo { return $this->belongsTo(Term::class); }
    public function instructor(): BelongsTo { return $this->belongsTo(Instructor::class); }
    public function lockedBy(): BelongsTo { return $this->belongsTo(User::class, 'office_hours_locked_by_user_id'); }

    public static function for(Term $term, Instructor $instructor): self
    {
        return static::firstOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            ['office_hours_locked' => false]
        );
    }
}

PHP

write_file "app/Services/ScheduleConflictService.php" <<'PHP'
<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use Illuminate\Support\Collection;

class ScheduleConflictService
{
    /**
     * Returns true if time ranges overlap (exclusive end).
     */
    public static function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $a0 = self::toMinutes($startA);
        $a1 = self::toMinutes($endA);
        $b0 = self::toMinutes($startB);
        $b1 = self::toMinutes($endB);

        // [a0,a1) overlaps [b0,b1)
        return ($a0 < $b1) && ($b0 < $a1);
    }

    public static function dayOverlap(array $daysA, array $daysB): bool
    {
        $setB = array_fill_keys($daysB, true);
        foreach ($daysA as $d) {
            if (isset($setB[$d])) return true;
        }
        return false;
    }

    private static function toMinutes(string $hhmm): int
    {
        $hhmm = substr($hhmm, 0, 5);
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($h * 60) + $m;
    }

    /**
     * MeetingBlock room conflicts: class vs class only (office hours excluded).
     */
    public function roomConflictsForMeetingBlock(Term $term, int $roomId, array $days, string $startsAt, string $endsAt, ?int $ignoreMeetingBlockId = null): Collection
    {
        if (!$roomId) return collect();

        $q = MeetingBlock::query()
            ->where('room_id', $roomId)
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            });

        if ($ignoreMeetingBlockId) {
            $q->where('id', '!=', $ignoreMeetingBlockId);
        }

        $candidates = $q->get();

        return $candidates->filter(function (MeetingBlock $mb) use ($days, $startsAt, $endsAt) {
            $mbDays = $mb->days_json ?? [];
            if (!self::dayOverlap($days, $mbDays)) return false;
            return self::timesOverlap($startsAt, $endsAt, $mb->starts_at, $mb->ends_at);
        })->values();
    }

    /**
     * Instructor conflicts for meeting blocks:
     * - class vs class
     * - class vs office hours
     */
    public function instructorConflictsForMeetingBlock(Term $term, int $instructorId, array $days, string $startsAt, string $endsAt, ?int $ignoreMeetingBlockId = null): array
    {
        $meetingConflicts = MeetingBlock::query()
            ->whereHas('section', function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId);
            })
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            });

        if ($ignoreMeetingBlockId) {
            $meetingConflicts->where('id', '!=', $ignoreMeetingBlockId);
        }

        $meetingBlocks = $meetingConflicts->get()
            ->filter(function (MeetingBlock $mb) use ($days, $startsAt, $endsAt) {
                $mbDays = $mb->days_json ?? [];
                if (!self::dayOverlap($days, $mbDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $mb->starts_at, $mb->ends_at);
            })->values();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->where('instructor_id', $instructorId)
            ->get()
            ->filter(function (OfficeHourBlock $ob) use ($days, $startsAt, $endsAt) {
                $obDays = $ob->days_json ?? [];
                if (!self::dayOverlap($days, $obDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $ob->starts_at, $ob->ends_at);
            })->values();

        return [
            'meeting_blocks' => $meetingBlocks,
            'office_hour_blocks' => $officeBlocks,
        ];
    }

    /**
     * Instructor conflicts for office hour blocks:
     * - office hours vs office hours
     * - office hours vs class meeting blocks
     */
    public function instructorConflictsForOfficeHourBlock(Term $term, int $instructorId, array $days, string $startsAt, string $endsAt, ?int $ignoreOfficeHourBlockId = null): array
    {
        $officeQ = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->where('instructor_id', $instructorId);

        if ($ignoreOfficeHourBlockId) {
            $officeQ->where('id', '!=', $ignoreOfficeHourBlockId);
        }

        $officeBlocks = $officeQ->get()
            ->filter(function (OfficeHourBlock $ob) use ($days, $startsAt, $endsAt) {
                $obDays = $ob->days_json ?? [];
                if (!self::dayOverlap($days, $obDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $ob->starts_at, $ob->ends_at);
            })->values();

        $meetingBlocks = MeetingBlock::query()
            ->whereHas('section', function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId);
            })
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            })
            ->get()
            ->filter(function (MeetingBlock $mb) use ($days, $startsAt, $endsAt) {
                $mbDays = $mb->days_json ?? [];
                if (!self::dayOverlap($days, $mbDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $mb->starts_at, $mb->ends_at);
            })->values();

        return [
            'office_hour_blocks' => $officeBlocks,
            'meeting_blocks' => $meetingBlocks,
        ];
    }

    public static function formatMeetingBlockLabel(MeetingBlock $mb): string
    {
        $mb->loadMissing('section.offering.catalogCourse', 'room');
        $course = $mb->section->offering->catalogCourse->code ?? 'COURSE';
        $sec = $mb->section->section_code ?? 'SEC';
        $days = implode(',', $mb->days_json ?? []);
        $time = substr($mb->starts_at, 0, 5) . '-' . substr($mb->ends_at, 0, 5);
        $room = $mb->room?->name ?? '—';
        return "$course $sec ($days $time, Room: $room)";
    }

    public static function formatOfficeHourLabel(OfficeHourBlock $ob): string
    {
        $days = implode(',', $ob->days_json ?? []);
        $time = substr($ob->starts_at, 0, 5) . '-' . substr($ob->ends_at, 0, 5);
        return "Office Hours ($days $time)";
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
        $this->ensureUnlocked($term, $instructor);

        abort_if($officeHourBlock->term_id !== $term->id || $officeHourBlock->instructor_id !== $instructor->id, 400, 'Office hour block not in active term/instructor.');

        $officeHourBlock->delete();

        return redirect()->route('aop.schedule.officeHours.show', $instructor)->with('status', 'Office hours block deleted.');
    }

    public function lock(Request $request, Instructor $instructor)
    {
        $term = $this->activeTermOrFail();

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

        // Room conflicts: class vs class only
        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at']);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            return back()->withErrors(['conflicts' => $msg])->withInput();
        }

        // Instructor conflicts: class vs class + office hours
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

        // Room conflicts: class vs class only
        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at'], $meetingBlock->id);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            return back()->withErrors(['conflicts' => $msg])->withInput();
        }

        // Instructor conflicts: class vs class + office hours
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

write_file "routes/web.php" <<'PHP'
<?php

use App\Http\Controllers\Aop\CatalogCourseController;
use App\Http\Controllers\Aop\DashboardController;
use App\Http\Controllers\Aop\InstructorController;
use App\Http\Controllers\Aop\RoomController;
use App\Http\Controllers\Aop\TermController;
use App\Http\Controllers\Aop\Schedule\ScheduleHomeController;
use App\Http\Controllers\Aop\Schedule\OfferingController;
use App\Http\Controllers\Aop\Schedule\SectionController;
use App\Http\Controllers\Aop\Schedule\MeetingBlockController;
use App\Http\Controllers\Aop\Schedule\OfficeHoursController;
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
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

PHP

write_file "resources/views/aop/schedule/index.blade.php" <<'PHP'
<x-aop-layout>
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Schedule</h1>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before scheduling.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      <div class="actions" style="margin-top:10px;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
      </div>
    @endif
  </div>
</x-aop-layout>

PHP

write_file "resources/views/aop/schedule/office-hours/index.blade.php" <<'PHP'
<x-aop-layout>
  <x-slot:title>Office Hours</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Office Hours</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back</a>
    </div>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before managing office hours.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>

      <div style="height:12px;"></div>

      <h2>Select Instructor</h2>
      <form method="GET" action="{{ route('aop.schedule.officeHours.index') }}">
        <label>Instructor</label>
        <select onchange="if(this.value){ window.location = '{{ url('/aop/schedule/office-hours') }}/' + this.value; }">
          <option value="">— Select —</option>
          @foreach ($instructors as $i)
            <option value="{{ $i->id }}">{{ $i->name }}</option>
          @endforeach
        </select>
        <p style="margin-top:8px;">Office hours are scoped to the active term.</p>
      </form>
    @endif
  </div>
</x-aop-layout>

PHP

write_file "resources/views/aop/schedule/office-hours/show.blade.php" <<'PHP'
<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Office Hours</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Office Hours — {{ $instructor->name }}</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.officeHours.index') }}">Back</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>Status</h2>
    <p>
      @if ($lock->office_hours_locked)
        <span class="badge">LOCKED</span>
        <span style="margin-left:8px; color:var(--muted);">
          {{ $lock->office_hours_locked_at ? $lock->office_hours_locked_at->format('Y-m-d H:i') : '' }}
        </span>
      @else
        <span class="badge">UNLOCKED</span>
      @endif
    </p>

    <div class="actions" style="margin-top:10px;">
      @if ($lock->office_hours_locked)
        <form method="POST" action="{{ route('aop.schedule.officeHours.unlock', $instructor) }}">
          @csrf
          <button class="btn secondary" type="submit">Unlock Office Hours</button>
        </form>
      @else
        <form method="POST" action="{{ route('aop.schedule.officeHours.lock', $instructor) }}">
          @csrf
          <button class="btn" type="submit">Lock Office Hours</button>
        </form>
      @endif
    </div>

    <p style="margin-top:10px;">
      Locking prevents add/edit/delete for this instructor in the active term.
    </p>
  </div>

  <div class="card">
    <h2>Office Hour Blocks (Active Term)</h2>

    <table style="margin-top:10px;">
      <thead>
        <tr>
          <th>Days</th>
          <th>Time</th>
          <th>Notes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($blocks as $b)
          <tr>
            <td>{{ implode(', ', $b->days_json ?? []) }}</td>
            <td>{{ substr($b->starts_at,0,5) }}–{{ substr($b->ends_at,0,5) }}</td>
            <td>{{ $b->notes ? \Illuminate\Support\Str::limit($b->notes, 80) : '—' }}</td>
            <td style="white-space:nowrap;">
              <details>
                <summary style="cursor:pointer;">Edit</summary>

                @if ($lock->office_hours_locked)
                  <div style="margin-top:10px; color:var(--muted);">Locked. Unlock to edit.</div>
                @else
                  <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.update', [$instructor, $b]) }}">
                    @csrf
                    @method('PUT')

                    <label>Days</label>
                    <div class="split">
                      @foreach ($days as $d)
                        <label style="display:flex; gap:8px; align-items:center; margin:6px 0;">
                          <input type="checkbox" name="days[]" value="{{ $d }}" style="width:auto;" {{ in_array($d, $b->days_json ?? []) ? 'checked' : '' }} />
                          <span>{{ $d }}</span>
                        </label>
                      @endforeach
                    </div>

                    <div class="split">
                      <div>
                        <label>Start</label>
                        <input type="time" name="starts_at" required value="{{ substr($b->starts_at,0,5) }}" />
                      </div>
                      <div>
                        <label>End</label>
                        <input type="time" name="ends_at" required value="{{ substr($b->ends_at,0,5) }}" />
                      </div>
                    </div>

                    <label>Notes</label>
                    <textarea name="notes">{{ $b->notes }}</textarea>

                    <div class="actions" style="margin-top:10px;">
                      <button class="btn" type="submit">Save</button>
                    </div>
                  </form>

                  <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.destroy', [$instructor, $b]) }}" onsubmit="return confirm('Delete this office hours block?');" style="margin-top:10px;">
                    @csrf
                    @method('DELETE')
                    <button class="btn secondary" type="submit">Delete</button>
                  </form>
                @endif
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="4">No office hours yet.</td></tr>
        @endforelse
      </tbody>
    </table>

    <div style="height:14px;"></div>

    <details>
      <summary style="cursor:pointer; font-weight:700;">Add Office Hours Block</summary>

      @if ($lock->office_hours_locked)
        <div style="margin-top:10px; color:var(--muted);">Locked. Unlock to add blocks.</div>
      @else
        <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.store', $instructor) }}">
          @csrf

          <label>Days</label>
          <div class="split">
            @foreach ($days as $d)
              <label style="display:flex; gap:8px; align-items:center; margin:6px 0;">
                <input type="checkbox" name="days[]" value="{{ $d }}" style="width:auto;" />
                <span>{{ $d }}</span>
              </label>
            @endforeach
          </div>

          <div class="split">
            <div>
              <label>Start</label>
              <input type="time" name="starts_at" required />
            </div>
            <div>
              <label>End</label>
              <input type="time" name="ends_at" required />
            </div>
          </div>

          <label>Notes</label>
          <textarea name="notes"></textarea>

          <div style="height:12px;"></div>
          <button class="btn" type="submit">Add Block</button>
        </form>
      @endif
    </details>
  </div>
</x-aop-layout>

PHP

echo
echo "Phase 5 files written."
echo "Next steps:"
echo "  php artisan migrate"
echo "  (optional) php artisan optimize:clear"
