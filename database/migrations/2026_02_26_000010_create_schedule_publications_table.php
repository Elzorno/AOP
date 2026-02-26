<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_publications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->unsignedInteger('version');

            $table->text('notes')->nullable();

            $table->dateTime('published_at');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Base directory under storage/app (local disk), e.g. "aop/published/2026SP/v3"
            $table->string('storage_base_path', 255);

            $table->timestamps();

            $table->unique(['term_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_publications');
    }
};
