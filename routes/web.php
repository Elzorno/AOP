<?php

use App\Http\Controllers\Aop\CatalogCourseController;
use App\Http\Controllers\Aop\DashboardController;
use App\Http\Controllers\Aop\InstructorController;
use App\Http\Controllers\Aop\LabTechController;
use App\Http\Controllers\Aop\RoomController;
use App\Http\Controllers\Aop\Schedule\InstructorPreferenceController;
use App\Http\Controllers\Aop\Schedule\MeetingBlockController;
use App\Http\Controllers\Aop\Schedule\OfferingController;
use App\Http\Controllers\Aop\Schedule\OfficeHoursController;
use App\Http\Controllers\Aop\Schedule\OpenLabController;
use App\Http\Controllers\Aop\Schedule\ScheduleCalendarController;
use App\Http\Controllers\Aop\Schedule\ScheduledEventController;
use App\Http\Controllers\Aop\Schedule\ScheduleGridController;
use App\Http\Controllers\Aop\Schedule\ScheduleHomeController;
use App\Http\Controllers\Aop\Schedule\SchedulePublishController;
use App\Http\Controllers\Aop\Schedule\ScheduleReadinessController;
use App\Http\Controllers\Aop\Schedule\ScheduleReportsController;
use App\Http\Controllers\Aop\Schedule\ScheduleTermLockController;
use App\Http\Controllers\Aop\Schedule\SectionController;
use App\Http\Controllers\Aop\TermController;
use App\Http\Controllers\Aop\UserInstructorLinkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\SchedulePublicController;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::prefix('/public/schedule')->name('public.schedule.')->middleware('throttle:30,1')->group(function () {
    Route::get('/{termCode}/{version}/{token}', [SchedulePublicController::class, 'show'])
        ->whereNumber('version')
        ->name('show');

    Route::get('/{termCode}/{version}/{token}/download/term', [SchedulePublicController::class, 'downloadTerm'])
        ->whereNumber('version')
        ->name('download.term');

    Route::get('/{termCode}/{version}/{token}/download/instructors', [SchedulePublicController::class, 'downloadInstructorsZip'])
        ->whereNumber('version')
        ->name('download.instructors');

    Route::get('/{termCode}/{version}/{token}/download/rooms', [SchedulePublicController::class, 'downloadRoomsZip'])
        ->whereNumber('version')
        ->name('download.rooms');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Instructor specific
    Route::post('/instructor-preferences', [App\Http\Controllers\Aop\InstructorPreferenceController::class, 'store'])->name('aop.instructor_preferences.store');
    Route::post('/instructor-office-hours/{instructor}/blocks', [OfficeHoursController::class, 'storeForPortal'])->name('instructor.officeHours.store');
    Route::delete('/instructor-office-hours/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'destroyForPortal'])->name('instructor.officeHours.destroy');

    Route::prefix('/aop')->name('aop.')->middleware(['admin'])->group(function () {
        Route::get('/accounts', [UserInstructorLinkController::class, 'index'])->name('accounts.index');
        Route::put('/accounts/{user}/instructor', [UserInstructorLinkController::class, 'update'])->name('accounts.instructor.update');

        // Terms
        Route::get('/terms', [TermController::class, 'index'])->name('terms.index');
        Route::get('/terms/create', [TermController::class, 'create'])->name('terms.create');
        Route::post('/terms', [TermController::class, 'store'])->name('terms.store');
        Route::get('/terms/{sourceTerm}/clone', [TermController::class, 'cloneCreate'])->name('terms.clone.create');
        Route::post('/terms/{sourceTerm}/clone', [TermController::class, 'cloneStore'])->name('terms.clone.store');
        Route::get('/terms/{term}/edit', [TermController::class, 'edit'])->name('terms.edit');
        Route::put('/terms/{term}', [TermController::class, 'update'])->name('terms.update');
        Route::post('/terms/active', [TermController::class, 'setActive'])->name('terms.setActive');
        Route::post('/terms/{term}/draft', [TermController::class, 'makeDraft'])->name('terms.draft');
        Route::post('/terms/{term}/publish', [TermController::class, 'publish'])->name('terms.publish');
        Route::post('/terms/{term}/select-schedule', [TermController::class, 'selectForSchedule'])->name('terms.schedule.select');

        // Instructors
        Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
        Route::get('/instructors/create', [InstructorController::class, 'create'])->name('instructors.create');
        Route::post('/instructors', [InstructorController::class, 'store'])->name('instructors.store');
        Route::get('/instructors/{instructor}/edit', [InstructorController::class, 'edit'])->name('instructors.edit');
        Route::put('/instructors/{instructor}', [InstructorController::class, 'update'])->name('instructors.update');
        Route::post('/instructors/{instructor}/preferences', [InstructorPreferenceController::class, 'upsert'])->name('instructors.preferences.upsert');

        // Lab Techs
        Route::get('/labtechs', [LabTechController::class, 'index'])->name('labtechs.index');
        Route::get('/labtechs/create', [LabTechController::class, 'create'])->name('labtechs.create');
        Route::post('/labtechs', [LabTechController::class, 'store'])->name('labtechs.store');
        Route::get('/labtechs/{labTech}/edit', [LabTechController::class, 'edit'])->name('labtechs.edit');
        Route::put('/labtechs/{labTech}', [LabTechController::class, 'update'])->name('labtechs.update');

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
        Route::get('/catalog/{course}/edit', [CatalogCourseController::class, 'edit'])->name('catalog.edit');
        Route::put('/catalog/{course}', [CatalogCourseController::class, 'update'])->name('catalog.update');

        // Schedule studio
        Route::get('/terms/{term}/schedule', [ScheduleHomeController::class, 'index'])->name('schedule.home');
        Route::get('/terms/{term}/schedule/distribution/preview', [ScheduleHomeController::class, 'distributionPreview'])->name('schedule.distribution.preview');
        Route::get('/schedule', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.home', array_merge(['term' => $term], $request->query()));
        })->name('schedule.home.legacy');
        Route::prefix('/terms/{term}/schedule/events')->group(function () {
            Route::get('/', [ScheduledEventController::class, 'index'])->name('schedule.events.index');
            Route::get('/create', [ScheduledEventController::class, 'create'])->name('schedule.events.create');
            Route::post('/', [ScheduledEventController::class, 'store'])->name('schedule.events.store');
        });

        Route::get('/schedule/events', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.events.index', $term);
        })->name('schedule.events.legacy');

        // Offerings are the first fully explicit term-scoped scheduling resource.
        Route::prefix('/terms/{term}/schedule/offerings')->name('schedule.offerings.')->group(function () {
            Route::get('/', [OfferingController::class, 'index'])->name('index');
            Route::get('/create', [OfferingController::class, 'create'])->name('create');
            Route::post('/', [OfferingController::class, 'store'])->name('store');
            Route::delete('/{offering}', [OfferingController::class, 'destroy'])->name('destroy');
        });

        Route::get('/schedule/offerings', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.offerings.index', $term);
        })->name('schedule.offerings.legacy');

        // Sections and their meeting blocks are explicitly term scoped.
        Route::prefix('/terms/{term}/schedule/sections')->group(function () {
            Route::get('/', [SectionController::class, 'index'])->name('schedule.sections.index');
            Route::get('/create', [SectionController::class, 'create'])->name('schedule.sections.create');
            Route::post('/', [SectionController::class, 'store'])->name('schedule.sections.store');
            Route::post('/bulk-assign', [SectionController::class, 'bulkAssign'])->name('schedule.sections.bulkAssign');
            Route::get('/{section}/edit', [SectionController::class, 'edit'])->name('schedule.sections.edit');
            Route::put('/{section}', [SectionController::class, 'update'])->name('schedule.sections.update');
            Route::get('/{section}/suggest', [SectionController::class, 'suggest'])->name('schedule.sections.suggest');
            Route::delete('/{section}', [SectionController::class, 'destroy'])->name('schedule.sections.destroy');
            Route::post('/{section}/meeting-blocks', [MeetingBlockController::class, 'store'])->name('schedule.meetingBlocks.store');
            Route::put('/{section}/meeting-blocks/{meetingBlock}', [MeetingBlockController::class, 'update'])->name('schedule.meetingBlocks.update');
            Route::delete('/{section}/meeting-blocks/{meetingBlock}', [MeetingBlockController::class, 'destroy'])->name('schedule.meetingBlocks.destroy');
        });

        Route::get('/schedule/sections', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.sections.index', array_merge(['term' => $term], $request->query()));
        })->name('schedule.sections.legacy');

        // Office Hours
        Route::prefix('/terms/{term}/schedule/office-hours')->group(function () {
            Route::get('/', [OfficeHoursController::class, 'index'])->name('schedule.officeHours.index');
            Route::get('/{instructor}', [OfficeHoursController::class, 'show'])->name('schedule.officeHours.show');
            Route::post('/{instructor}/blocks', [OfficeHoursController::class, 'store'])->name('schedule.officeHours.blocks.store');
            Route::put('/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'update'])->name('schedule.officeHours.blocks.update');
            Route::delete('/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'destroy'])->name('schedule.officeHours.blocks.destroy');
            Route::post('/{instructor}/lock', [OfficeHoursController::class, 'lock'])->name('schedule.officeHours.lock');
            Route::post('/{instructor}/unlock', [OfficeHoursController::class, 'unlock'])->name('schedule.officeHours.unlock');
            Route::put('/{instructor}/preferences', [InstructorPreferenceController::class, 'upsertForTerm'])->name('schedule.officeHours.preferences.upsert');
            Route::delete('/{instructor}/preferences', [InstructorPreferenceController::class, 'destroyForTerm'])->name('schedule.officeHours.preferences.destroy');
        });

        Route::get('/schedule/office-hours', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.officeHours.index', $term);
        })->name('schedule.officeHours.legacy');

        // Open Labs
        Route::prefix('/terms/{term}/schedule/open-labs')->group(function () {
            Route::get('/', [OpenLabController::class, 'index'])->name('schedule.openLabs.index');
            Route::get('/create', [OpenLabController::class, 'create'])->name('schedule.openLabs.create');
            Route::post('/', [OpenLabController::class, 'store'])->name('schedule.openLabs.store');
            Route::get('/{openLab}/edit', [OpenLabController::class, 'edit'])->name('schedule.openLabs.edit');
            Route::put('/{openLab}', [OpenLabController::class, 'update'])->name('schedule.openLabs.update');
            Route::delete('/{openLab}', [OpenLabController::class, 'destroy'])->name('schedule.openLabs.destroy');
        });

        Route::get('/schedule/open-labs', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.openLabs.index', $term);
        })->name('schedule.openLabs.legacy');

        // Schedule Grids (active term)
        Route::get('/schedule/grids', [ScheduleGridController::class, 'index'])->name('schedule.grids.index');
        Route::get('/schedule/grids/instructors/{instructor}', [ScheduleGridController::class, 'instructor'])->name('schedule.grids.instructor');
        Route::get('/schedule/grids/instructors/{instructor}/pdf', [ScheduleGridController::class, 'instructorPdf'])->name('schedule.grids.instructor.pdf');
        Route::get('/schedule/grids/rooms/{room}', [ScheduleGridController::class, 'room'])->name('schedule.grids.room');
        Route::get('/schedule/grids/rooms/{room}/pdf', [ScheduleGridController::class, 'roomPdf'])->name('schedule.grids.room.pdf');

        // Schedule Calendar
        Route::prefix('/terms/{term}/schedule/calendar')->group(function () {
            Route::get('/', [ScheduleCalendarController::class, 'index'])->name('schedule.calendar.index');
            Route::get('/events', [ScheduleCalendarController::class, 'events'])->name('schedule.calendar.events');
            Route::post('/update', [ScheduleCalendarController::class, 'update'])->name('schedule.calendar.update');
        });

        Route::get('/schedule/calendar', function (Request $request) {
            $term = $request->user()->selectedTerm
                ?? Term::query()->where('is_active', true)->firstOrFail();

            return redirect()->route('aop.schedule.calendar.index', $term);
        })->name('schedule.calendar.legacy');

        // Schedule Reports (active term)
        Route::get('/schedule/reports', [ScheduleReportsController::class, 'index'])->name('schedule.reports.index');
        Route::get('/schedule/reports/export/registrar', [ScheduleReportsController::class, 'exportRegistrar'])->name('schedule.reports.exportRegistrar');
        Route::get('/schedule/reports/export/term', [ScheduleReportsController::class, 'exportTerm'])->name('schedule.reports.exportTerm');
        Route::get('/schedule/reports/export/instructors/{instructor}', [ScheduleReportsController::class, 'exportInstructor'])->name('schedule.reports.exportInstructor');
        Route::get('/schedule/reports/export/rooms/{room}', [ScheduleReportsController::class, 'exportRoom'])->name('schedule.reports.exportRoom');

        // Legacy schedule snapshots remain downloadable; new exports use the registrar CSV report.
        Route::get('/schedule/publish', [SchedulePublishController::class, 'index'])->name('schedule.publish.index');
        Route::post('/schedule/publish', [SchedulePublishController::class, 'store'])->name('schedule.publish.store');
        Route::get('/schedule/publish/{publication}/download/term', [SchedulePublishController::class, 'downloadTerm'])->name('schedule.publish.downloadTerm');
        Route::get('/schedule/publish/{publication}/download/instructors', [SchedulePublishController::class, 'downloadInstructorsZip'])->name('schedule.publish.downloadInstructorsZip');
        Route::get('/schedule/publish/{publication}/download/rooms', [SchedulePublishController::class, 'downloadRoomsZip'])->name('schedule.publish.downloadRoomsZip');

        // Readiness + Term Lock (active term)
        Route::get('/schedule/readiness', [ScheduleReadinessController::class, 'index'])->name('schedule.readiness.index');
        Route::post('/schedule/lock', [ScheduleTermLockController::class, 'lock'])->name('schedule.lock');
        Route::post('/schedule/unlock', [ScheduleTermLockController::class, 'unlock'])->name('schedule.unlock');

    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
