<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('syllabus_renders', function (Blueprint $table) {
      $table->id();
      $table->foreignId('syllabus_id')->constrained('syllabi')->cascadeOnDelete();
      $table->string('format'); // DOCX or PDF
      $table->string('path');
      $table->string('sha256')->nullable();
      $table->timestamp('rendered_at')->nullable();
      $table->string('status')->default('SUCCESS'); // SUCCESS/FAILED
      $table->text('error_message')->nullable();
      $table->timestamps();
      $table->index(['syllabus_id','format']);
    });
  }
  public function down(): void { Schema::dropIfExists('syllabus_renders'); }
};
