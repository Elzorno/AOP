<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->string('schedule_timezone', 64)->default('UTC')->after('schedule_policy_version');
        });

        Schema::create('term_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('occurs_on');
            $table->string('exception_type', 24);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['term_id', 'occurs_on', 'exception_type', 'scheduled_event_id'], 'term_schedule_exception_unique');
            $table->index(['term_id', 'occurs_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_schedule_exceptions');
        Schema::table('terms', function (Blueprint $table) {
            $table->dropColumn('schedule_timezone');
        });
    }
};
