<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedSmallInteger('section_capacity')->nullable()->after('notes');
            $table->unsignedSmallInteger('enrolled_count')->default(0)->after('section_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn(['section_capacity', 'enrolled_count']);
        });
    }
};
