<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleGridPrintViewTest extends TestCase
{
    use RefreshDatabase;

    private static int $courseCounter = 600;

    public function test_room_print_view_uses_letter_layout_and_hides_unused_days(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'LIB-201', 'is_active' => true]);
        $instructor = Instructor::create([
            'name' => 'Dr. Room Grid',
            'email' => 'room-grid@example.test',
            'is_full_time' => false,
            'is_active' => true,
            'color_hex' => '#3366AA',
        ]);

        $section = $this->createSection($term, ['instructor_id' => $instructor->id]);

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '09:00',
            'ends_at' => '10:15',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.grids.room', $room).'?print=1');

        $response->assertOk();
        $response->assertSee('@page', false);
        $response->assertSee('letter landscape', false);
        $response->assertSee('data-print-frame', false);
        $response->assertSeeText('LIB-201');
        $response->assertSeeText('Mon');
        $response->assertSeeText('Wed');
        $response->assertDontSeeText('Sat');
        $response->assertDontSeeText('Sun');
        $response->assertDontSeeText('Back to Grids');
    }

    public function test_instructor_print_view_includes_office_hours_and_hides_unused_days(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['code' => '2026FA']);
        $room = Room::create(['name' => 'ENG-310', 'is_active' => true]);
        $instructor = Instructor::create([
            'name' => 'Dr. Office Hours',
            'email' => 'office-hours@example.test',
            'is_full_time' => true,
            'is_active' => true,
            'color_hex' => '#8B5CF6',
        ]);

        $section = $this->createSection($term, ['instructor_id' => $instructor->id]);

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue', 'Thu'],
            'starts_at' => '11:00',
            'ends_at' => '12:15',
            'room_id' => $room->id,
        ]);

        OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Fri'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
            'notes' => 'Library annex',
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.grids.instructor', $instructor).'?print=1');

        $response->assertOk();
        $response->assertSee('@page', false);
        $response->assertSee('data-print-grid', false);
        $response->assertSeeText('Office Hours');
        $response->assertSeeText('Library annex');
        $response->assertSeeText('Tue');
        $response->assertSeeText('Thu');
        $response->assertSeeText('Fri');
        $response->assertDontSeeText('Sat');
        $response->assertDontSeeText('Sun');
        $response->assertDontSeeText('Back to Grids');
    }

    public function test_room_pdf_export_returns_pdf_download(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'LIB-205', 'is_active' => true]);
        $instructor = Instructor::create([
            'name' => 'Dr. PDF Room',
            'email' => 'pdf-room@example.test',
            'is_full_time' => false,
            'is_active' => true,
            'color_hex' => '#3366AA',
        ]);

        $section = $this->createSection($term, ['instructor_id' => $instructor->id]);

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '09:00',
            'ends_at' => '10:15',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.grids.room.pdf', $room));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
    }

    public function test_instructor_pdf_export_returns_pdf_download(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['code' => '2026FA']);
        $room = Room::create(['name' => 'ENG-315', 'is_active' => true]);
        $instructor = Instructor::create([
            'name' => 'Dr. PDF Instructor',
            'email' => 'pdf-instructor@example.test',
            'is_full_time' => true,
            'is_active' => true,
            'color_hex' => '#8B5CF6',
        ]);

        $section = $this->createSection($term, ['instructor_id' => $instructor->id]);

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue', 'Thu'],
            'starts_at' => '11:00',
            'ends_at' => '12:15',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.grids.instructor.pdf', $instructor));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
    }

    private function createActiveTerm(array $overrides = []): Term
    {
        return Term::create(array_merge([
            'code' => '2026SP',
            'name' => 'Spring 2026',
            'is_active' => true,
            'status' => 'draft',
            'weeks_in_term' => 15,
            'slot_minutes' => 15,
            'buffer_minutes' => 10,
            'schedule_locked' => false,
        ], $overrides));
    }

    private function createSection(Term $term, array $overrides = []): Section
    {
        self::$courseCounter++;

        $course = CatalogCourse::create([
            'code' => 'PRN'.self::$courseCounter,
            'title' => 'Print Course '.self::$courseCounter,
            'credits' => 3,
            'is_active' => true,
        ]);

        $offering = Offering::create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);

        return Section::create(array_merge([
            'offering_id' => $offering->id,
            'section_code' => 'P'.self::$courseCounter,
            'modality' => 'IN_PERSON',
            'instructor_id' => null,
        ], $overrides));
    }
}
