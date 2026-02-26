<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('catalog_courses', function (Blueprint $table) {
      $table->id();
      $table->string('code')->unique();
      $table->string('title');
      $table->decimal('credits', 4, 2)->default(0);

      $table->decimal('lecture_hours_per_week', 5, 2)->nullable();
      $table->decimal('lab_hours_per_week', 5, 2)->nullable();

      $table->text('description')->nullable();
      $table->text('prereq_text')->nullable();
      $table->text('coreq_text')->nullable();

      $table->boolean('is_active')->default(true);
      $table->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('catalog_courses'); }
};
