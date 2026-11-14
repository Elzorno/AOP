<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_labs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_tech_id')->constrained('lab_techs')->cascadeOnDelete();
            $table->json('days_json');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['term_id', 'room_id']);
            $table->index(['term_id', 'lab_tech_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_labs');
    }
};
