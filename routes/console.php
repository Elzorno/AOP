<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Models\Term;
use App\Services\ScheduleEventSyncService;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('aop:reset-schedule-data {--force : Actually delete terms and schedule data} {--keep-files : Keep published schedule artifacts in storage}', function () {
    $tables = [
        'terms',
        'offerings',
        'sections',
        'meeting_blocks',
        'open_labs',
        'scheduled_events',
        'scheduled_event_staff_assignments',
        'office_hour_blocks',
        'instructor_term_locks',
        'schedule_publications',
    ];

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
    }

    $this->warn('This will remove all terms and scheduling data, but leave the course catalog intact.');
    $this->line('Also preserved: instructors, lab techs, rooms, users, rules, and the course catalog.');
    $this->newLine();

    $rows = [];
    foreach ($counts as $table => $count) {
        $rows[] = [$table, $count === null ? 'missing' : (string) $count];
    }
    $this->table(['Table', 'Rows'], $rows);

    if (!$this->option('force')) {
        $this->comment('Dry run only. Re-run with --force to perform the reset.');
        return Command::SUCCESS;
    }

    if (!$this->confirm('Delete the tables listed above? This cannot be undone.', false)) {
        $this->comment('Reset cancelled.');
        return Command::FAILURE;
    }

    try {
        DB::transaction(function () use ($tables) {
            // Delete dependent/legacy tables first so environments with partial/legacy schemas are cleaned safely.
            foreach (['schedule_publications', 'scheduled_event_staff_assignments', 'scheduled_events', 'open_labs', 'meeting_blocks', 'sections', 'offerings', 'office_hour_blocks', 'instructor_term_locks', 'terms'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });
    } catch (Throwable $e) {
        $this->error('Database reset failed: ' . $e->getMessage());
        return Command::FAILURE;
    }

    if (!$this->option('keep-files')) {
        $deleteAbsoluteDir = function (string $absolutePath): void {
            if (File::isDirectory($absolutePath)) {
                File::deleteDirectory($absolutePath);
            }
        };

        foreach ([
            ['disk' => 'local', 'path' => 'aop/published'],
        ] as $target) {
            try {
                Storage::disk($target['disk'])->deleteDirectory($target['path']);
            } catch (Throwable $e) {
                $this->warn('Storage cleanup warning for ' . $target['disk'] . ':' . $target['path'] . ' — ' . $e->getMessage());
            }
        }

        foreach ([
            storage_path('app/aop/published'),
            storage_path('app/private/aop/published'),
        ] as $absolutePath) {
            $deleteAbsoluteDir($absolutePath);
        }

    }

    $remainingTerms = Schema::hasTable('terms') ? DB::table('terms')->count() : 0;
    $remainingOfferings = Schema::hasTable('offerings') ? DB::table('offerings')->count() : 0;
    $remainingSections = Schema::hasTable('sections') ? DB::table('sections')->count() : 0;

    $this->info('AOP schedule reset complete.');
    $this->line('Remaining terms: ' . $remainingTerms);
    $this->line('Remaining offerings: ' . $remainingOfferings);
    $this->line('Remaining sections: ' . $remainingSections);

    if ($this->option('keep-files')) {
        $this->comment('Generated files were preserved because --keep-files was used.');
    } else {
        $this->comment('Generated published schedule artifacts were removed.');
    }

    return Command::SUCCESS;
})->purpose('Delete terms and scheduling data while preserving the course catalog');

Artisan::command('aop:sync-scheduled-events {--term= : Term ID to inspect; defaults to every term} {--repair : Upsert source-backed projections after reporting drift}', function (ScheduleEventSyncService $sync) {
    $terms = Term::query()
        ->when($this->option('term'), fn ($query, $termId) => $query->whereKey($termId))
        ->orderBy('id')
        ->get();

    if ($terms->isEmpty()) {
        $this->error('No matching terms found.');
        return Command::FAILURE;
    }

    $rows = $terms->map(function (Term $term) use ($sync) {
        $counts = $sync->reconcileTerm($term);

        return [$term->code, $counts['sources'], $counts['events'], $counts['missing'], $counts['mismatched'], $counts['unmatched'], $counts['link_mismatches']];
    })->all();

    $this->table(['Term', 'Sources', 'Events', 'Missing', 'Mismatched', 'Unmatched', 'Bad links'], $rows);
    if (! $this->option('repair')) {
        $this->comment('Dry run only. Re-run with --repair to upsert source-backed projections.');
        return Command::SUCCESS;
    }

    $terms->each(fn (Term $term) => $sync->rebuildTerm($term));
    $this->info('Scheduled event projections repaired without deleting unmatched events.');

    return Command::SUCCESS;
})->purpose('Non-destructively repair shared scheduled events from current source records');
