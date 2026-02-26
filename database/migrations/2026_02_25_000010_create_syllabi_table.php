<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('syllabi', function (Blueprint $table) {
      $table->id();
      $table->foreignId('section_id')->constrained()->cascadeOnDelete();
      $table->json('header_snapshot_json')->nullable();
      $table->json('block_order_json')->nullable();
      $table->timestamps();
      $table->unique(['section_id']);
    });
  }
  public function down(): void { Schema::dropIfExists('syllabi'); }
};
