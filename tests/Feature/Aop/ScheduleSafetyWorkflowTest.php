<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleSafetyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private static int $courseCounter = 100;

    public function test_calendar_update_blocked_when_schedule_locked(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['schedule_locked' => true]);
        $section = $this->createSection($term);
        $room = Room::create(['name' => 'ENG-101', 'is_active' => true]);

        $block = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update'), [
            'blockId' => $block->id,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.term.0', 'Schedule is locked for the active term. Unlock it before making schedule changes.');

        $this->assertSame('09:00', substr((string) $block->fresh()->starts_at, 0, 5));
    }

    public function test_calendar_update_rejects_end_before_or_equal_start(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $section = $this->createSection($term);
        $room = Room::create(['name' => 'ENG-102', 'is_active' => true]);

        $block = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update'), [
            'blockId' => $block->id,
            'starts_at' => '10:00',
            'ends_at' => '10:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.ends_at.0', 'End time must be after start time.');
    }

    public function test_calendar_update_rejects_room_conflict(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['buffer_minutes' => 10]);

        $room = Room::create(['name' => 'SCI-201', 'is_active' => true]);

        $sectionA = $this->createSection($term);
        MeetingBlock::create([
            'section_id' => $sectionA->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);

        $sectionB = $this->createSection($term);
        $target = MeetingBlock::create([
            'section_id' => $sectionB->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '12:00',
            'ends_at' => '13:00',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update'), [
            'blockId' => $target->id,
            'starts_at' => '09:30',
            'ends_at' => '10:30',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertStringContainsString('Room conflict', (string) $response->json('message'));
    }

    public function test_calendar_update_rejects_instructor_office_hour_conflict(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['buffer_minutes' => 5]);
        $instructor = Instructor::create([
            'name' => 'Dr. Ada',
            'email' => 'ada@example.test',
            'is_full_time' => true,
            'is_active' => true,
        ]);

        $section = $this->createSection($term, ['instructor_id' => $instructor->id]);
        $room = Room::create(['name' => 'SCI-301', 'is_active' => true]);

        $target = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue'],
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'room_id' => $room->id,
        ]);

        OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Tue'],
            'starts_at' => '11:00',
            'ends_at' => '12:00',
        ]);

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update'), [
            'blockId' => $target->id,
            'starts_at' => '11:30',
            'ends_at' => '12:30',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertStringContainsString('office hours', strtolower((string) $response->json('message')));
    }

    public function test_successful_calendar_update_persists_valid_changes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $section = $this->createSection($term);
        $room = Room::create(['name' => 'SCI-401', 'is_active' => true]);

        $block = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Fri'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update'), [
            'blockId' => $block->id,
            'starts_at' => '10:00',
            'ends_at' => '11:15',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $block->refresh();
        $this->assertSame('10:00', substr((string) $block->starts_at, 0, 5));
        $this->assertSame('11:15', substr((string) $block->ends_at, 0, 5));
    }

    public function test_sections_index_missing_meeting_blocks_filter_works(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();

        $missing = $this->createSection($term, ['section_code' => 'MISSING-01']);
        $withBlock = $this->createSection($term, ['section_code' => 'BLOCKED-01']);

        $room = Room::create(['name' => 'BUS-101', 'is_active' => true]);
        MeetingBlock::create([
            'section_id' => $withBlock->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.sections.index', [
            'missing' => 'meeting_blocks',
        ]));

        $response->assertOk();
        $response->assertSeeText($missing->section_code);
        $response->assertDontSeeText($withBlock->section_code);
    }

    public function test_publish_requires_confirmation_when_readiness_has_blockers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['schedule_locked' => true]);

        // Create a section with missing instructor and no meeting blocks to trigger blockers.
        $this->createSection($term, [
            'section_code' => 'PUB-BLOCK-01',
            'instructor_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('aop.schedule.publish.store'), [
            'notes' => 'Attempt publish with blockers.',
        ]);

        $response->assertSessionHasErrors('publish_gate');
        $this->assertDatabaseCount('schedule_publications', 0);
    }

    public function test_publish_allows_override_when_readiness_has_blockers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['schedule_locked' => true]);

        $this->createSection($term, [
            'section_code' => 'PUB-OVERRIDE-01',
            'instructor_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('aop.schedule.publish.store'), [
            'notes' => 'Intentional publish with known blockers.',
            'confirm_publish_with_issues' => '1',
        ]);

        $response->assertRedirect(route('aop.schedule.publish.index'));

        $publication = SchedulePublication::query()->first();
        $this->assertNotNull($publication);
        $this->assertSame(1, $publication->version);
    }

    public function test_publish_without_blockers_does_not_require_override(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['schedule_locked' => true]);

        $instructor = Instructor::create([
            'name' => 'Dr. Publish Ready',
            'email' => 'publish-ready@example.test',
            'is_full_time' => false,
            'is_active' => true,
        ]);

        $room = Room::create(['name' => 'PUB-101', 'is_active' => true]);
        $section = $this->createSection($term, [
            'section_code' => 'PUB-CLEAN-01',
            'instructor_id' => $instructor->id,
        ]);

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '10:00',
            'ends_at' => '11:15',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->post(route('aop.schedule.publish.store'), [
            'notes' => 'Publish clean schedule snapshot.',
        ]);

        $response->assertRedirect(route('aop.schedule.publish.index'));
        $this->assertDatabaseCount('schedule_publications', 1);
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
            'code' => 'CSE'.self::$courseCounter,
            'title' => 'Course '.self::$courseCounter,
            'credits' => 3,
            'is_active' => true,
        ]);

        $offering = Offering::create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);

        return Section::create(array_merge([
            'offering_id' => $offering->id,
            'section_code' => 'A'.self::$courseCounter,
            'modality' => 'IN_PERSON',
            'instructor_id' => null,
        ], $overrides));
    }
}
