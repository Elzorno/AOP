<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffInstructorSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_be_selected_as_an_intentional_instructor_listing_and_exported(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $offering = $this->createOffering($term);
        $room = Room::create(['name' => 'STAFF-101', 'is_active' => true]);

        $this->actingAs($admin)->post(route('aop.schedule.sections.store'), [
            'offering_id' => $offering->id,
            'section_code' => '01',
            'instructor_id' => Section::STAFF_ASSIGNMENT,
            'modality' => 'IN_PERSON',
        ])->assertRedirect(route('aop.schedule.sections.index'));

        $section = Section::query()->firstOrFail();
        $this->assertNull($section->instructor_id);
        $this->assertTrue($section->instructor_is_staff);
        $this->assertSame('STAFF', $section->instructor_display_name);

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '10:00',
            'ends_at' => '11:15',
            'room_id' => $room->id,
        ]);

        $this->actingAs($admin)->get(route('aop.schedule.readiness.index'))
            ->assertOk()
            ->assertViewHas('sectionsMissingInstructor', fn ($sections) => $sections->isEmpty());

        $this->actingAs($admin)->get(route('aop.schedule.grids.room', $room))
            ->assertOk()
            ->assertSeeText('STAFF');

        $response = $this->actingAs($admin)->get(route('aop.schedule.reports.exportRegistrar'));
        $response->assertOk()->assertDownload('aop_2026fa_registrar_schedule.csv');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('STAFF', $csv);
    }

    public function test_changing_a_named_instructor_to_staff_removes_the_person_assignment(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $offering = $this->createOffering($term);
        $instructor = Instructor::create([
            'name' => 'Named Instructor',
            'is_active' => true,
        ]);
        $section = Section::create([
            'offering_id' => $offering->id,
            'section_code' => '02',
            'instructor_id' => $instructor->id,
            'modality' => 'ONLINE',
        ]);
        $meetingBlock = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue'],
            'starts_at' => '12:00',
            'ends_at' => '13:00',
        ]);

        $event = ScheduledEvent::query()
            ->where('source_type', MeetingBlock::class)
            ->where('source_id', $meetingBlock->id)
            ->firstOrFail();
        $this->assertDatabaseHas('scheduled_event_staff_assignments', [
            'scheduled_event_id' => $event->id,
            'staff_type' => 'instructor',
            'staff_id' => $instructor->id,
        ]);

        $this->actingAs($admin)->put(route('aop.schedule.sections.update', $section), [
            'section_code' => '02',
            'instructor_id' => Section::STAFF_ASSIGNMENT,
            'modality' => 'ONLINE',
        ])->assertRedirect(route('aop.schedule.sections.edit', $section));

        $section->refresh();
        $this->assertNull($section->instructor_id);
        $this->assertTrue($section->instructor_is_staff);
        $this->assertDatabaseMissing('scheduled_event_staff_assignments', [
            'scheduled_event_id' => $event->id,
            'staff_type' => 'instructor',
            'staff_id' => $instructor->id,
        ]);
    }

    private function createActiveTerm(): Term
    {
        return Term::create([
            'code' => '2026FA',
            'name' => 'Fall 2026',
            'is_active' => true,
            'status' => 'draft',
            'weeks_in_term' => 15,
            'slot_minutes' => 15,
            'buffer_minutes' => 10,
            'schedule_locked' => false,
        ]);
    }

    private function createOffering(Term $term): Offering
    {
        $course = CatalogCourse::create([
            'code' => 'STAFF100',
            'title' => 'Staffed Course',
            'credits' => 3,
            'is_active' => true,
        ]);

        return Offering::create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);
    }
}
