<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('meeting_blocks', function (Blueprint $table) {
      $table->id();
      $table->foreignId('section_id')->constrained()->cascadeOnDelete();
      $table->string('type')->default('LECTURE');
      $table->json('days_json');
      $table->time('starts_at');
      $table->time('ends_at');
      $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
      $table->text('notes')->nullable();
      $table->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('meeting_blocks'); }
};
