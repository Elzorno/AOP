<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If the table doesn't exist, do nothing here (the create migration will handle it).
        if (!Schema::hasTable('syllabus_renders')) {
            return;
        }

        // If term_id already exists, nothing to do.
        if (Schema::hasColumn('syllabus_renders', 'term_id')) {
            return;
        }

        Schema::table('syllabus_renders', function (Blueprint $table) {
            // Keep it nullable for safety; we can backfill later if needed.
            $table->unsignedBigInteger('term_id')->nullable()->after('id');
            $table->index(['term_id']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('syllabus_renders')) {
            return;
        }
        if (!Schema::hasColumn('syllabus_renders', 'term_id')) {
            return;
        }

        Schema::table('syllabus_renders', function (Blueprint $table) {
            // SQLite supports dropColumn in modern versions; if this fails, it's safe to leave it.
            $table->dropIndex(['term_id']);
            $table->dropColumn('term_id');
        });
    }
};
