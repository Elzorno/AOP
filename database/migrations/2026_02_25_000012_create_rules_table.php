<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('rules', function (Blueprint $table) {
      $table->id();
      $table->string('key')->unique();
      $table->string('name');
      $table->string('severity')->default('WARNING'); // INFO/WARNING/ERROR
      $table->string('scope')->default('TERM'); // SECTION/INSTRUCTOR/ROOM/TERM/PROGRAM
      $table->boolean('is_enabled')->default(true);
      $table->json('config_json')->nullable();
      $table->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('rules'); }
};
