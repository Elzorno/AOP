<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('syllabus_section_items');
        Schema::dropIfExists('syllabus_section_definitions');
        Schema::dropIfExists('syllabus_renders');
        Schema::dropIfExists('syllabus_blocks');
        Schema::dropIfExists('syllabi');
        Schema::enableForeignKeyConstraints();

        if (Schema::hasColumn('catalog_courses', 'default_syllabus_block_set_json')) {
            Schema::table('catalog_courses', function (Blueprint $table) {
                $table->dropColumn('default_syllabus_block_set_json');
            });
        }
    }

    public function down(): void
    {
        // Syllabi feature is retired — not restoring these tables or columns.
    }
};
