<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('syllabus_section_definitions')) {
            Schema::create('syllabus_section_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('category')->nullable();
                $table->text('description')->nullable();
                $table->longText('default_content')->nullable();
                $table->string('scope')->default('global'); // global | syllabus
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_locked')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order'], 'syll_def_active_sort_idx');
            });
        }

        if (!Schema::hasTable('syllabus_section_items')) {
            Schema::create('syllabus_section_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('syllabus_id')->constrained('syllabi')->cascadeOnDelete();
                $table->foreignId('syllabus_section_definition_id')->constrained('syllabus_section_definitions')->cascadeOnDelete();
                $table->string('title_override')->nullable();
                $table->longText('content_markdown')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->nullable();
                $table->timestamps();

                $table->unique(['syllabus_id', 'syllabus_section_definition_id'], 'syll_item_unique_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_section_items');
        Schema::dropIfExists('syllabus_section_definitions');
    }
};
