<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_change_logs', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_change_logs', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable(false)->change();
        });
    }
};
