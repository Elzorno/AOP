<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('offerings', function (Blueprint $table) {
      $table->id();
      $table->foreignId('term_id')->constrained()->cascadeOnDelete();
      $table->foreignId('catalog_course_id')->constrained()->cascadeOnDelete();

      $table->string('delivery_method')->nullable();
      $table->text('notes')->nullable();
      $table->text('prereq_override')->nullable();
      $table->text('coreq_override')->nullable();
      $table->json('default_syllabus_block_set_json')->nullable();

      $table->timestamps();
      $table->unique(['term_id','catalog_course_id']);
    });
  }
  public function down(): void { Schema::dropIfExists('offerings'); }
};
