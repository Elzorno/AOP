<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_courses', function (Blueprint $table) {
            $table->boolean('is_gep')->default(false)->after('is_active');
            $table->boolean('is_program_required')->default(false)->after('is_gep');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_courses', function (Blueprint $table) {
            $table->dropColumn(['is_gep', 'is_program_required']);
        });
    }
};
