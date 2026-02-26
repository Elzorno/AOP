<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('terms', function (Blueprint $table) {
      $table->id();
      $table->string('code')->unique();
      $table->string('name');
      $table->date('starts_on')->nullable();
      $table->date('ends_on')->nullable();
      $table->boolean('is_active')->default(false);

      $table->unsignedInteger('weeks_in_term')->default(15);
      $table->unsignedInteger('slot_minutes')->default(15);
      $table->unsignedInteger('buffer_minutes')->default(10);
      $table->json('allowed_hours_json')->nullable();

      $table->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('terms'); }
};
