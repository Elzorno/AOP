<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('syllabus_blocks', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->string('category')->nullable();
      $table->longText('content_html')->nullable();
      $table->boolean('is_locked')->default(false);
      $table->string('version')->nullable();
      $table->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('syllabus_blocks'); }
};
