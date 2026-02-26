<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('catalog_courses', function (Blueprint $table) {
            if (!Schema::hasColumn('catalog_courses', 'department')) {
                $table->string('department', 255)->nullable()->after('title');
            }
            if (!Schema::hasColumn('catalog_courses', 'objectives')) {
                $table->text('objectives')->nullable()->after('description');
            }
            if (!Schema::hasColumn('catalog_courses', 'required_materials')) {
                $table->text('required_materials')->nullable()->after('objectives');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_courses', function (Blueprint $table) {
            $cols = ['department','objectives','required_materials'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('catalog_courses', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
