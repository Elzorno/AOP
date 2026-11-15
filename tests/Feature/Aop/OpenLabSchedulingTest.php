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
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\ScheduleSuggestionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpenLabSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private static int $courseCounter = 900;

    public function test_readiness_conflicts_use_authoritative_event_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $firstRoom = Room::create(['name' => 'ATC-226A', 'is_active' => true]);
        $secondRoom = Room::create(['name' => 'ATC-226B', 'is_active' => true]);
        $first = MeetingBlock::create([
            'section_id' => $this->createSection($term)->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $firstRoom->id,
        ]);
        $second = MeetingBlock::create([
            'section_id' => $this->createSection($term)->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
            'room_id' => $secondRoom->id,
        ]);
        ScheduledEvent::query()
            ->where('source_type', MeetingBlock::class)
            ->where('source_id', $second->id)
            ->update([
                'days_json' => json_encode(['Mon']),
                'starts_at' => '09:30',
                'ends_at' => '10:30',
                'room_id' => $firstRoom->id,
            ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.readiness.index', $term))
            ->assertOk()
            ->assertViewHas('roomConflicts', fn ($conflicts) => count($conflicts) === 1
                && $conflicts[0]['a']->id === $first->id
                && $conflicts[0]['b']->id === $second->id);
    }

    public function test_schedule_suggestions_use_authoritative_event_time_and_room(): void
    {
        $term = $this->createActiveTerm();
        $occupiedRoom = Room::create(['name' => 'ATC-227A', 'is_active' => true]);
        $availableRoom = Room::create(['name' => 'ATC-227B', 'is_active' => true]);
        $existingSection = $this->createSection($term);
        $targetSection = $this->createSection($term);
        $block = MeetingBlock::create([
            'section_id' => $existingSection->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
            'room_id' => $occupiedRoom->id,
        ]);
        ScheduledEvent::query()
            ->where('source_type', MeetingBlock::class)
            ->where('source_id', $block->id)
            ->update(['days_json' => json_encode(['Mon', 'Wed', 'Fri']), 'starts_at' => '08:00', 'ends_at' => '08:55']);

        $suggestions = app(ScheduleSuggestionService::class)->suggestSlots($targetSection, 'LECTURE', 20);
        $slot = collect($suggestions)->first(
            fn (array $suggestion) => $suggestion['days'] === ['Mon', 'Wed', 'Fri'] && $suggestion['starts_at'] === '08:00'
        );

        $this->assertNotNull($slot);
        $this->assertNotContains($occupiedRoom->id, array_column($slot['available_rooms'], 'id'));
        $this->assertContains($availableRoom->id, array_column($slot['available_rooms'], 'id'));
    }

    public function test_open_lab_creation_rejects_room_overlap_with_class(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-228', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Jordan Tech',
            'email' => 'jordan@example.test',
            'weekly_hour_limit' => 20,
            'is_active' => true,
        ]);

        $section = $this->createSection($term);
        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LAB',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'room_id' => $room->id,
        ]);

        $response = $this->actingAs($admin)->post(route('aop.schedule.openLabs.store', $term), [
            'name' => 'Open Lab Coverage',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days' => ['Mon'],
            'starts_at' => '10:30',
            'ends_at' => '11:30',
        ]);

        $response->assertSessionHasErrors('conflicts');
        $this->assertDatabaseCount('open_labs', 0);
        $this->assertDatabaseCount('scheduled_events', 1);
        $this->assertDatabaseCount('schedule_change_logs', 0);
    }

    public function test_open_lab_creation_rejects_labtech_hour_overage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-229', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Avery Hours',
            'email' => 'avery@example.test',
            'weekly_hour_limit' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('aop.schedule.openLabs.store', $term), [
            'name' => 'Extended Open Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days' => ['Mon', 'Wed', 'Fri'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);

        $response->assertSessionHasErrors('lab_tech_id');
        $this->assertDatabaseCount('open_labs', 0);
        $this->assertDatabaseCount('scheduled_events', 0);
        $this->assertDatabaseCount('schedule_change_logs', 0);
    }

    public function test_room_grid_includes_open_labs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-230', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Morgan Coverage',
            'email' => 'morgan@example.test',
            'weekly_hour_limit' => 15,
            'is_active' => true,
        ]);

        OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Cyber Drop-In Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Tue'],
            'starts_at' => '13:00',
            'ends_at' => '15:00',
            'notes' => 'Walk-in support',
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.grids.room', [$term, $room]));

        $response->assertOk();
        $response->assertSeeText('Open Lab');
        $response->assertSeeText('Cyber Drop-In Lab');
        $response->assertSeeText('Morgan Coverage');
    }

    public function test_room_grid_filters_and_renders_from_authoritative_event(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $legacyRoom = Room::create(['name' => 'ATC-230A', 'is_active' => true]);
        $eventRoom = Room::create(['name' => 'ATC-230B', 'is_active' => true]);
        $labTech = LabTech::create(['name' => 'Grid Authority', 'weekly_hour_limit' => 15, 'is_active' => true]);
        $openLab = OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Event-Owned Grid Lab',
            'room_id' => $legacyRoom->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Tue'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
        ]);
        ScheduledEvent::query()->where('source_type', OpenLab::class)->where('source_id', $openLab->id)->update([
            'room_id' => $eventRoom->id,
            'days_json' => json_encode(['Wed']),
            'starts_at' => '15:00',
            'ends_at' => '16:00',
        ]);

        $this->actingAs($admin)->get(route('aop.schedule.grids.room', [$term, $legacyRoom]))
            ->assertOk()->assertDontSee('Event-Owned Grid Lab');
        $this->actingAs($admin)->get(route('aop.schedule.grids.room', [$term, $eventRoom]))
            ->assertOk()->assertSee('Event-Owned Grid Lab');
        $csv = $this->actingAs($admin)
            ->get(route('aop.schedule.reports.exportRoom', [$term, $eventRoom]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Event-Owned Grid Lab', $csv);
        $this->assertStringContainsString('Wed,15:00,16:00', $csv);
    }

    public function test_room_schedule_csv_includes_open_labs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-231', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Taylor Publish',
            'email' => 'taylor@example.test',
            'weekly_hour_limit' => 12,
            'is_active' => true,
        ]);

        OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Published Open Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Thu'],
            'starts_at' => '14:00',
            'ends_at' => '16:00',
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.reports.exportRoom', [$term, $room]));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('OPEN_LAB', $csv);
        $this->assertStringContainsString('Published Open Lab', $csv);
        $this->assertStringContainsString('Taylor Publish', $csv);
    }

    public function test_legacy_schedule_records_are_projected_as_shared_events(): void
    {
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-232', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Casey Projection',
            'weekly_hour_limit' => 10,
            'is_active' => true,
        ]);
        $section = $this->createSection($term);

        $meetingBlock = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);
        $openLab = OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Projection Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Tue'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
        ]);

        $this->assertDatabaseHas('scheduled_events', [
            'term_id' => $term->id,
            'event_type' => ScheduledEvent::TYPE_CLASS,
            'source_type' => MeetingBlock::class,
            'source_id' => $meetingBlock->id,
        ]);
        $this->assertDatabaseHas('scheduled_events', [
            'term_id' => $term->id,
            'event_type' => ScheduledEvent::TYPE_OPEN_LAB,
            'source_type' => OpenLab::class,
            'source_id' => $openLab->id,
        ]);

        $event = ScheduledEvent::query()->where('source_type', OpenLab::class)->where('source_id', $openLab->id)->firstOrFail();
        $this->assertDatabaseHas('open_labs', [
            'id' => $openLab->id,
            'scheduled_event_id' => $event->id,
        ]);
        $this->assertDatabaseHas('scheduled_event_staff_assignments', [
            'scheduled_event_id' => $event->id,
            'staff_type' => 'lab_tech',
            'staff_id' => $labTech->id,
            'lab_tech_id' => $labTech->id,
            'instructor_id' => null,
            'role' => 'lab_tech',
        ]);

        $openLab->delete();
        $this->assertDatabaseMissing('scheduled_events', ['id' => $event->id]);
    }

    public function test_staff_assignment_requires_exactly_one_valid_typed_target(): void
    {
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-232B', 'is_active' => true]);
        $labTech = LabTech::create(['name' => 'Constraint Tech', 'weekly_hour_limit' => 10, 'is_active' => true]);
        $openLab = OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Constrained Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Tue'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
        ]);
        $event = ScheduledEvent::query()->where('source_type', OpenLab::class)->where('source_id', $openLab->id)->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('scheduled_event_staff_assignments')->where('scheduled_event_id', $event->id)->update([
            'instructor_id' => $labTech->id,
        ]);
    }

    public function test_section_staff_changes_refresh_the_projected_event_assignment(): void
    {
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-233', 'is_active' => true]);
        $firstInstructor = Instructor::create(['name' => 'First Instructor', 'is_active' => true]);
        $secondInstructor = Instructor::create(['name' => 'Second Instructor', 'is_active' => true]);
        $section = $this->createSection($term, ['instructor_id' => $firstInstructor->id]);
        $meetingBlock = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);

        $section->update(['instructor_id' => $secondInstructor->id]);
        $event = ScheduledEvent::query()->where('source_type', MeetingBlock::class)->where('source_id', $meetingBlock->id)->firstOrFail();

        $this->assertDatabaseMissing('scheduled_event_staff_assignments', [
            'scheduled_event_id' => $event->id,
            'staff_type' => 'instructor',
            'staff_id' => $firstInstructor->id,
        ]);
        $this->assertDatabaseHas('scheduled_event_staff_assignments', [
            'scheduled_event_id' => $event->id,
            'staff_type' => 'instructor',
            'staff_id' => $secondInstructor->id,
            'role' => 'instructor',
        ]);
    }

    public function test_scheduled_event_projection_repair_restores_missing_rows_without_deleting_unmatched_rows(): void
    {
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-234', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Rebuild Tech',
            'weekly_hour_limit' => 10,
            'is_active' => true,
        ]);

        $openLab = OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Rebuild Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Fri'],
            'starts_at' => '10:00',
            'ends_at' => '12:00',
        ]);
        ScheduledEvent::query()->delete();
        $unmatched = ScheduledEvent::query()->create([
            'term_id' => $term->id,
            'event_type' => 'future_type',
            'source_type' => 'Future\\AuthoritativeEvent',
            'source_id' => 999,
            'display_name' => 'Preserve for reconciliation',
            'days_json' => ['Mon'],
            'starts_at' => '08:00',
            'ends_at' => '09:00',
        ]);

        $this->artisan('aop:sync-scheduled-events', ['--term' => $term->id])
            ->expectsOutputToContain('Dry run only')
            ->assertExitCode(0);
        $this->assertDatabaseCount('scheduled_events', 1);

        $this->artisan('aop:sync-scheduled-events', ['--term' => $term->id, '--repair' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('scheduled_events', [
            'term_id' => $term->id,
            'event_type' => ScheduledEvent::TYPE_OPEN_LAB,
        ]);
        $restoredEvent = ScheduledEvent::query()->where('source_type', OpenLab::class)->where('source_id', $openLab->id)->firstOrFail();
        $this->assertDatabaseHas('open_labs', ['id' => $openLab->id, 'scheduled_event_id' => $restoredEvent->id]);
        $this->assertDatabaseHas('scheduled_events', [
            'id' => $unmatched->id,
            'display_name' => 'Preserve for reconciliation',
        ]);
    }

    public function test_unified_event_workflow_creates_class_and_open_lab_events(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-235', 'is_active' => true]);
        $section = $this->createSection($term);
        $labTech = LabTech::create([
            'name' => 'Unified Workflow Tech',
            'weekly_hour_limit' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('aop.schedule.events.store', $term), [
            'event_type' => 'class',
            'section_id' => $section->id,
            'category' => 'LECTURE',
            'room_id' => $room->id,
            'days' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ])->assertRedirect(route('aop.schedule.events.index', $term));

        $this->actingAs($admin)->post(route('aop.schedule.events.store', $term), [
            'event_type' => 'open_lab',
            'name' => 'Unified Open Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days' => ['Tue'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ])->assertRedirect(route('aop.schedule.events.index', $term));

        $this->assertDatabaseCount('scheduled_events', 2);
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'user_id' => $admin->id,
            'action' => 'created',
        ]);
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'section_id' => null,
            'user_id' => $admin->id,
            'action' => 'created_open_lab',
        ]);
        $this->actingAs($admin)->get(route('aop.schedule.events.index', $term))
            ->assertOk()
            ->assertSeeText('Unified Open Lab');
    }

    public function test_office_hours_are_projected_as_instructor_schedule_events(): void
    {
        $term = $this->createActiveTerm();
        $instructor = Instructor::create(['name' => 'Office Hour Instructor', 'is_active' => true]);

        $officeHours = OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Wed'],
            'starts_at' => '12:00',
            'ends_at' => '13:00',
        ]);

        $event = ScheduledEvent::query()
            ->where('source_type', OfficeHourBlock::class)
            ->where('source_id', $officeHours->id)
            ->firstOrFail();

        $this->assertSame(ScheduledEvent::TYPE_OFFICE_HOUR, $event->event_type);
        $this->assertDatabaseHas('scheduled_event_staff_assignments', [
            'scheduled_event_id' => $event->id,
            'staff_type' => 'instructor',
            'staff_id' => $instructor->id,
            'role' => 'office_hours',
        ]);
    }

    public function test_deleting_an_instructor_removes_staff_assignments_and_office_hour_events(): void
    {
        $term = $this->createActiveTerm();
        $instructor = Instructor::create(['name' => 'Departing Instructor', 'is_active' => true]);
        $section = $this->createSection($term, ['instructor_id' => $instructor->id]);
        $meetingBlock = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);
        OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Tue'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);

        $instructor->delete();

        $classEvent = ScheduledEvent::query()->where('source_type', MeetingBlock::class)->where('source_id', $meetingBlock->id)->firstOrFail();
        $this->assertDatabaseMissing('scheduled_event_staff_assignments', ['scheduled_event_id' => $classEvent->id]);
        $this->assertDatabaseMissing('scheduled_events', ['source_type' => OfficeHourBlock::class]);
    }

    public function test_calendar_reads_open_labs_from_shared_scheduled_events(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'ATC-236', 'is_active' => true]);
        $labTech = LabTech::create([
            'name' => 'Calendar Tech',
            'weekly_hour_limit' => 10,
            'is_active' => true,
        ]);
        OpenLab::create([
            'term_id' => $term->id,
            'name' => 'Calendar Open Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Thu'],
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);

        $this->actingAs($admin)->getJson(route('aop.schedule.calendar.events', $term))
            ->assertOk()
            ->assertJsonFragment([
                'eventKind' => ScheduledEvent::TYPE_OPEN_LAB,
                'scheduledEventId' => ScheduledEvent::query()->value('id'),
            ]);
    }

    private function createActiveTerm(array $overrides = []): Term
    {
        return Term::create(array_merge([
            'code' => '2026FA',
            'name' => 'Fall 2026',
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
            'code' => 'LAB'.self::$courseCounter,
            'title' => 'Lab Course '.self::$courseCounter,
            'credits' => 3,
            'is_active' => true,
        ]);

        $offering = Offering::create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);

        return Section::create(array_merge([
            'offering_id' => $offering->id,
            'section_code' => 'L'.self::$courseCounter,
            'modality' => 'IN_PERSON',
            'instructor_id' => null,
        ], $overrides));
    }
}
