<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('sections', function (Blueprint $table) {
      $table->id();
      $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
      $table->string('section_code');
      $table->foreignId('instructor_id')->nullable()->constrained('instructors')->nullOnDelete();
      $table->string('modality')->default('IN_PERSON');
      $table->text('notes')->nullable();
      $table->timestamps();
      $table->unique(['offering_id','section_code']);
    });
  }
  public function down(): void { Schema::dropIfExists('sections'); }
};
