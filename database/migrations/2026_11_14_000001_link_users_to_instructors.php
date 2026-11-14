<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('instructor_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('instructors')
                ->nullOnDelete();
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $email = mb_strtolower(trim((string) $user->email));
            if ($email === '') {
                return;
            }

            $matches = DB::table('instructors')
                ->whereNotNull('email')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->pluck('id');

            if ($matches->count() !== 1 || DB::table('users')->where('instructor_id', $matches->first())->exists()) {
                return;
            }

            DB::table('users')->where('id', $user->id)->update(['instructor_id' => $matches->first()]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instructor_id');
        });
    }
};
