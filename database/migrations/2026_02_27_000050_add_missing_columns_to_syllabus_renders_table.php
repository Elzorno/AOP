<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This migration is defensive/idempotent.
     * Some environments already have a syllabus_renders table created with a reduced schema.
     * We add any missing columns needed by the current app without destroying data.
     */
    public function up(): void
    {
        if (!Schema::hasTable('syllabus_renders')) {
            return;
        }

        // SQLite-friendly column existence check.
        $existing = collect(DB::select("PRAGMA table_info('syllabus_renders')"))
            ->map(fn ($row) => $row->name ?? null)
            ->filter()
            ->values()
            ->all();

        $has = fn (string $col) => in_array($col, $existing, true);

        Schema::table('syllabus_renders', function (Blueprint $table) use ($has) {
            // Foreign keys are not enforced in SQLite by default in this project; keep nullable for safety.
            if (!$has('term_id')) {
                $table->unsignedInteger('term_id')->nullable()->index();
            }
            if (!$has('section_id')) {
                $table->unsignedInteger('section_id')->nullable()->index();
            }

            if (!$has('format')) {
                $table->string('format')->nullable()->index();
            }
            if (!$has('status')) {
                $table->string('status')->default('SUCCESS')->index();
            }

            if (!$has('storage_path')) {
                $table->string('storage_path')->nullable();
            }
            if (!$has('file_size')) {
                $table->unsignedBigInteger('file_size')->nullable();
            }
            if (!$has('sha1')) {
                $table->string('sha1')->nullable();
            }
            if (!$has('error_message')) {
                $table->text('error_message')->nullable();
            }
            if (!$has('completed_at')) {
                $table->dateTime('completed_at')->nullable()->index();
            }

            // Standard timestamps; only add if missing.
            if (!$has('created_at')) {
                $table->dateTime('created_at')->nullable();
            }
            if (!$has('updated_at')) {
                $table->dateTime('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally no-op: do not drop columns in a defensive migration.
    }
};
