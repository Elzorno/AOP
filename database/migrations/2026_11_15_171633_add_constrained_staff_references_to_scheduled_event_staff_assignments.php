<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $invalid = DB::table('scheduled_event_staff_assignments as assignments')
            ->leftJoin('instructors', fn ($join) => $join->on('assignments.staff_id', '=', 'instructors.id')->where('assignments.staff_type', 'instructor'))
            ->leftJoin('lab_techs', fn ($join) => $join->on('assignments.staff_id', '=', 'lab_techs.id')->where('assignments.staff_type', 'lab_tech'))
            ->where(fn ($query) => $query
                ->whereNotIn('assignments.staff_type', ['instructor', 'lab_tech'])
                ->orWhere(fn ($typed) => $typed->where('assignments.staff_type', 'instructor')->whereNull('instructors.id'))
                ->orWhere(fn ($typed) => $typed->where('assignments.staff_type', 'lab_tech')->whereNull('lab_techs.id')))
            ->exists();

        if ($invalid) {
            throw new RuntimeException('Cannot constrain scheduled-event staff assignments while invalid legacy references exist.');
        }

        DB::statement(<<<'SQL'
            CREATE TABLE scheduled_event_staff_assignments_constrained (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                scheduled_event_id INTEGER NOT NULL,
                staff_type VARCHAR NOT NULL,
                staff_id INTEGER NOT NULL,
                instructor_id INTEGER NULL,
                lab_tech_id INTEGER NULL,
                role VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                CONSTRAINT scheduled_event_staff_event_fk FOREIGN KEY (scheduled_event_id) REFERENCES scheduled_events (id) ON DELETE CASCADE,
                CONSTRAINT scheduled_event_staff_instructor_fk FOREIGN KEY (instructor_id) REFERENCES instructors (id) ON DELETE CASCADE,
                CONSTRAINT scheduled_event_staff_lab_tech_fk FOREIGN KEY (lab_tech_id) REFERENCES lab_techs (id) ON DELETE CASCADE,
                CONSTRAINT scheduled_event_staff_exact_target CHECK (
                    (staff_type = 'instructor' AND instructor_id = staff_id AND lab_tech_id IS NULL)
                    OR (staff_type = 'lab_tech' AND lab_tech_id = staff_id AND instructor_id IS NULL)
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO scheduled_event_staff_assignments_constrained
                (id, scheduled_event_id, staff_type, staff_id, instructor_id, lab_tech_id, role, created_at, updated_at)
            SELECT id, scheduled_event_id, staff_type, staff_id,
                CASE WHEN staff_type = 'instructor' THEN staff_id END,
                CASE WHEN staff_type = 'lab_tech' THEN staff_id END,
                role, created_at, updated_at
            FROM scheduled_event_staff_assignments
            SQL);
        DB::statement('DROP TABLE scheduled_event_staff_assignments');
        DB::statement('ALTER TABLE scheduled_event_staff_assignments_constrained RENAME TO scheduled_event_staff_assignments');
        DB::statement('CREATE UNIQUE INDEX schedule_event_staff_unique ON scheduled_event_staff_assignments (scheduled_event_id, staff_type, staff_id, role)');
        DB::statement('CREATE INDEX scheduled_event_staff_assignments_staff_type_staff_id_index ON scheduled_event_staff_assignments (staff_type, staff_id)');
        DB::statement('CREATE INDEX scheduled_event_staff_assignments_instructor_id_index ON scheduled_event_staff_assignments (instructor_id)');
        DB::statement('CREATE INDEX scheduled_event_staff_assignments_lab_tech_id_index ON scheduled_event_staff_assignments (lab_tech_id)');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE scheduled_event_staff_assignments_legacy (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                scheduled_event_id INTEGER NOT NULL,
                staff_type VARCHAR NOT NULL,
                staff_id INTEGER NOT NULL,
                role VARCHAR NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (scheduled_event_id) REFERENCES scheduled_events (id) ON DELETE CASCADE
            )
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO scheduled_event_staff_assignments_legacy
                (id, scheduled_event_id, staff_type, staff_id, role, created_at, updated_at)
            SELECT id, scheduled_event_id, staff_type, staff_id, role, created_at, updated_at
            FROM scheduled_event_staff_assignments
            SQL);
        DB::statement('DROP TABLE scheduled_event_staff_assignments');
        DB::statement('ALTER TABLE scheduled_event_staff_assignments_legacy RENAME TO scheduled_event_staff_assignments');
        DB::statement('CREATE UNIQUE INDEX schedule_event_staff_unique ON scheduled_event_staff_assignments (scheduled_event_id, staff_type, staff_id, role)');
        DB::statement('CREATE INDEX scheduled_event_staff_assignments_staff_type_staff_id_index ON scheduled_event_staff_assignments (staff_type, staff_id)');
    }
};
