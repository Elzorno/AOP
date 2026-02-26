<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_publications', function (Blueprint $table) {
            // Public share token (used in Phase 9 public read-only views)
            $table->string('public_token', 64)->nullable()->after('storage_base_path');
        });

        Schema::table('schedule_publications', function (Blueprint $table) {
            $table->unique('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_publications', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
