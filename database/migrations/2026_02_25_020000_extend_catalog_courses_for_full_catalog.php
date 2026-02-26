<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('catalog_courses', function (Blueprint $table) {
            if (!Schema::hasColumn('catalog_courses', 'credits_text')) {
                $table->string('credits_text')->nullable()->after('credits');
            }
            if (!Schema::hasColumn('catalog_courses', 'credits_min')) {
                $table->decimal('credits_min', 4, 2)->nullable()->after('credits_text');
            }
            if (!Schema::hasColumn('catalog_courses', 'credits_max')) {
                $table->decimal('credits_max', 4, 2)->nullable()->after('credits_min');
            }
            if (!Schema::hasColumn('catalog_courses', 'contact_hours_per_week')) {
                $table->decimal('contact_hours_per_week', 5, 2)->nullable()->after('lab_hours_per_week');
            }
            if (!Schema::hasColumn('catalog_courses', 'course_lab_fee')) {
                $table->string('course_lab_fee', 20)->nullable()->after('contact_hours_per_week');
            }
            if (!Schema::hasColumn('catalog_courses', 'notes')) {
                $table->text('notes')->nullable()->after('coreq_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_courses', function (Blueprint $table) {
            $cols = ['credits_text','credits_min','credits_max','contact_hours_per_week','course_lab_fee','notes'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('catalog_courses', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
