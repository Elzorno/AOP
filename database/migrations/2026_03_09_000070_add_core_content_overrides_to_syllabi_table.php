<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('syllabi', function (Blueprint $table) {
            $table->text('course_description_override')->nullable()->after('block_order_json');
            $table->text('course_objectives_override')->nullable()->after('course_description_override');
            $table->text('required_materials_override')->nullable()->after('course_objectives_override');
        });
    }

    public function down(): void
    {
        Schema::table('syllabi', function (Blueprint $table) {
            $table->dropColumn([
                'course_description_override',
                'course_objectives_override',
                'required_materials_override',
            ]);
        });
    }
};
