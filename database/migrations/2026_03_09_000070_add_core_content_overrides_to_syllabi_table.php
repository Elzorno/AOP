<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('syllabi')) {
            return;
        }

        $hasDescription = Schema::hasColumn('syllabi', 'course_description_override');
        $hasObjectives = Schema::hasColumn('syllabi', 'course_objectives_override');
        $hasMaterials = Schema::hasColumn('syllabi', 'required_materials_override');

        Schema::table('syllabi', function (Blueprint $table) use ($hasDescription, $hasObjectives, $hasMaterials) {
            if (!$hasDescription) {
                $table->longText('course_description_override')->nullable()->after('block_order_json');
            }
            if (!$hasObjectives) {
                $table->longText('course_objectives_override')->nullable()->after('course_description_override');
            }
            if (!$hasMaterials) {
                $table->longText('required_materials_override')->nullable()->after('course_objectives_override');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('syllabi')) {
            return;
        }

        $hasDescription = Schema::hasColumn('syllabi', 'course_description_override');
        $hasObjectives = Schema::hasColumn('syllabi', 'course_objectives_override');
        $hasMaterials = Schema::hasColumn('syllabi', 'required_materials_override');

        Schema::table('syllabi', function (Blueprint $table) use ($hasDescription, $hasObjectives, $hasMaterials) {
            $drops = [];
            if ($hasMaterials) {
                $drops[] = 'required_materials_override';
            }
            if ($hasObjectives) {
                $drops[] = 'course_objectives_override';
            }
            if ($hasDescription) {
                $drops[] = 'course_description_override';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
