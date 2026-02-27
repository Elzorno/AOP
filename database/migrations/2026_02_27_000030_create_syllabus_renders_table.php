<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency guard:
        // If the table already exists (e.g., created manually or by a prior failed/partial deploy),
        // exit cleanly so the migration can still be recorded in the migrations table.
        if (Schema::hasTable('syllabus_renders')) {
            return;
        }

        Schema::create('syllabus_renders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('section_id');

            $table->string('format'); // DOCX | PDF | HTML | JSON
            $table->string('status')->default('SUCCESS'); // SUCCESS | ERROR

            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha1')->nullable();

            $table->text('error_message')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->index(['term_id', 'section_id', 'format', 'status'], 'syll_renders_term_section_fmt_status_idx');
            $table->index(['created_at'], 'syll_renders_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_renders');
    }
};
