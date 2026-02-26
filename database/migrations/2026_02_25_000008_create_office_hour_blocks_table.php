<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('office_hour_blocks', function (Blueprint $table) {
      $table->id();
      $table->foreignId('term_id')->constrained()->cascadeOnDelete();
      $table->foreignId('instructor_id')->constrained('instructors')->cascadeOnDelete();
      $table->json('days_json');
      $table->time('starts_at');
      $table->time('ends_at');
      $table->text('notes')->nullable();
      $table->boolean('is_locked')->default(false);
      $table->timestamp('locked_at')->nullable();
      $table->timestamps();
      $table->index(['term_id','instructor_id']);
    });
  }
  public function down(): void { Schema::dropIfExists('office_hour_blocks'); }
};
