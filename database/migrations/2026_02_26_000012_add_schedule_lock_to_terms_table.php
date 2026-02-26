<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->boolean('schedule_locked')->default(false);
            $table->timestamp('schedule_locked_at')->nullable();
            $table->foreignId('schedule_locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_locked_by_user_id');
            $table->dropColumn(['schedule_locked', 'schedule_locked_at']);
        });
    }
};
