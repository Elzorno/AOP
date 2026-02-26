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
