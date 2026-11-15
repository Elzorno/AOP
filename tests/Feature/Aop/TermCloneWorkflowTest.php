<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\LabTech;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\ScheduleChangeLog;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermCloneWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_clone_preserves_selected_schedule_data_through_mutation_services(): void
    {
        $source = $this->term('SOURCE');
        $instructor = Instructor::query()->create(['name' => 'Clone Instructor', 'is_active' => true]);
        $room = Room::query()->create(['name' => 'CLONE-101', 'is_active' => true]);
        $labTech = LabTech::query()->create([
            'name' => 'Clone Technician',
            'weekly_hour_limit' => 20,
            'is_active' => true,
        ]);
        $section = $this->section($source, 'CLONE-100', $instructor, 24, 18);
        MeetingBlock::query()->create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '17:00',
            'ends_at' => '19:35',
            'room_id' => $room->id,
        ]);
        OfficeHourBlock::query()->create([
            'term_id' => $source->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Tue'],
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        OpenLab::query()->create([
            'term_id' => $source->id,
            'name' => 'Clone Open Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Wed'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.terms.clone.store', $source), $this->clonePayload('TARGET') + [
                'copy_instructor_assignments' => '1',
                'copy_office_hours' => '1',
            ])
            ->assertRedirect(route('aop.terms.index'));

        $target = Term::query()->where('code', 'TARGET')->firstOrFail();
        $targetSection = Section::query()
            ->whereHas('offering', fn ($query) => $query->where('term_id', $target->id))
            ->firstOrFail();
        $this->assertSame($instructor->id, $targetSection->instructor_id);
        $this->assertSame(24, $targetSection->section_capacity);
        $this->assertSame(18, $targetSection->enrolled_count);
        $this->assertSame(3, $target->scheduledEvents()->count());
        $this->assertSame(4, $target->hasMany(ScheduleChangeLog::class)->count());
        $this->assertDatabaseHas('office_hour_blocks', ['term_id' => $target->id, 'instructor_id' => $instructor->id]);
        $this->assertDatabaseHas('open_labs', ['term_id' => $target->id, 'name' => 'Clone Open Lab']);
        $this->assertDatabaseMissing('instructor_term_locks', ['term_id' => $target->id]);
        $this->assertDatabaseMissing('schedule_publications', ['term_id' => $target->id]);
    }

    public function test_draft_clone_uses_the_same_service_and_conflicting_clone_rolls_back_completely(): void
    {
        $source = $this->term('DRAFT-SOURCE');
        $instructor = Instructor::query()->create(['name' => 'Draft Instructor', 'is_active' => true]);
        $first = $this->section($source, 'DRAFT-101', $instructor);
        $second = $this->section($source, 'DRAFT-102', $instructor);
        foreach ([$first, $second] as $section) {
            MeetingBlock::query()->create([
                'section_id' => $section->id,
                'type' => 'LECTURE',
                'days_json' => ['Thu'],
                'starts_at' => '17:00',
                'ends_at' => '19:35',
            ]);
        }
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.terms.draft', $source))
            ->assertSessionHasErrors('conflicts');

        $this->assertDatabaseCount('terms', 1);
        $this->assertDatabaseCount('offerings', 2);
        $this->assertDatabaseCount('sections', 2);
        $this->assertDatabaseCount('meeting_blocks', 2);
        $this->assertSame(0, ScheduleChangeLog::query()->count());
    }

    private function term(string $code): Term
    {
        return Term::query()->create([
            'code' => $code,
            'name' => $code.' term',
            'is_active' => true,
            'status' => 'published',
            'weeks_in_term' => 15,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
            'enforce_schedule_blocks' => true,
        ]);
    }

    private function section(Term $term, string $courseCode, ?Instructor $instructor = null, ?int $capacity = null, int $enrolled = 0): Section
    {
        $course = CatalogCourse::query()->create([
            'code' => $courseCode,
            'title' => $courseCode.' course',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);

        return Section::query()->create([
            'offering_id' => $offering->id,
            'section_code' => '01',
            'instructor_id' => $instructor?->id,
            'modality' => 'ONLINE',
            'section_capacity' => $capacity,
            'enrolled_count' => $enrolled,
        ]);
    }

    private function clonePayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => $code.' term',
            'weeks_in_term' => 15,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
        ];
    }
}
