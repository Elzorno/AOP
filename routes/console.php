<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('aop:reset-schedule-data {--force : Actually delete terms and schedule data} {--keep-files : Keep generated schedule/syllabi artifacts in storage}', function () {
    $tables = [
        'terms',
        'offerings',
        'sections',
        'meeting_blocks',
        'office_hour_blocks',
        'instructor_term_locks',
        'syllabi',
        'syllabus_renders',
        'schedule_publications',
    ];

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
    }

    $this->warn('This will remove all terms and scheduling data, but leave the course catalog intact.');
    $this->line('Also preserved: instructors, rooms, users, rules, and syllabus templates.');
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
            foreach (['syllabus_renders', 'schedule_publications', 'syllabi', 'meeting_blocks', 'sections', 'offerings', 'office_hour_blocks', 'instructor_term_locks', 'terms'] as $table) {
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
            ['disk' => 'local', 'path' => 'aop/syllabi/generated'],
            ['disk' => 'local', 'path' => 'aop/syllabi/_render_tmp'],
        ] as $target) {
            try {
                Storage::disk($target['disk'])->deleteDirectory($target['path']);
            } catch (Throwable $e) {
                $this->warn('Storage cleanup warning for ' . $target['disk'] . ':' . $target['path'] . ' — ' . $e->getMessage());
            }
        }

        foreach ([
            storage_path('app/aop/published'),
            storage_path('app/aop/syllabi/generated'),
            storage_path('app/aop/syllabi/_render_tmp'),
            storage_path('app/private/aop/published'),
            storage_path('app/private/aop/syllabi/generated'),
            storage_path('app/private/aop/syllabi/_render_tmp'),
        ] as $absolutePath) {
            $deleteAbsoluteDir($absolutePath);
        }

        foreach ([
            storage_path('app/aop/syllabi'),
            storage_path('app/private/aop/syllabi'),
        ] as $syllabiRoot) {
            if (!File::isDirectory($syllabiRoot)) {
                continue;
            }

            foreach (File::directories($syllabiRoot) as $dir) {
                if (basename($dir) === 'templates') {
                    continue;
                }

                $deleteAbsoluteDir($dir);
            }
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
        $this->comment('Generated published schedule and syllabus artifacts were removed, while syllabus templates were preserved.');
    }

    return Command::SUCCESS;
})->purpose('Delete terms and scheduling data while preserving the course catalog');
