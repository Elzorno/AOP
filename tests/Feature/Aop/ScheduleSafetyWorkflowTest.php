<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Services\ScheduleRuleWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleSafetyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private static int $courseCounter = 100;

    public function test_legacy_schedule_lock_no_longer_blocks_calendar_updates(): void
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

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update', $term), [
            'blockId' => $block->id,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'revision' => $this->revision($block),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('10:00', substr((string) $block->fresh()->starts_at, 0, 5));
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

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update', $term), [
            'blockId' => $block->id,
            'starts_at' => '10:00',
            'ends_at' => '10:00',
            'revision' => $this->revision($block),
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

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update', $term), [
            'blockId' => $target->id,
            'starts_at' => '09:30',
            'ends_at' => '10:30',
            'revision' => $this->revision($target),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertStringContainsString('Room conflict', (string) $response->json('message'));
    }

    public function test_validation_preview_and_persisted_create_use_the_same_conflict_result(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'SCI-202', 'is_active' => true]);
        $occupiedSection = $this->createSection($term);
        MeetingBlock::create([
            'section_id' => $occupiedSection->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);
        $targetSection = $this->createSection($term);
        $payload = [
            'days' => ['Mon'],
            'starts_at' => '09:30',
            'ends_at' => '10:30',
            'room_id' => $room->id,
        ];

        $preview = $this->actingAs($admin)
            ->postJson(route('aop.schedule.calendar.preview', $term), [
                'section_id' => $targetSection->id,
                ...$payload,
            ])
            ->assertOk()
            ->assertJsonPath('valid', false);

        $create = $this->actingAs($admin)
            ->post(route('aop.schedule.meetingBlocks.store', [$term, $targetSection]), [
                'type' => 'LECTURE',
                ...$payload,
            ])
            ->assertSessionHasErrors('conflicts');

        $this->assertSame(
            $preview->json('errors.conflicts.0'),
            $create->getSession()->get('errors')->get('conflicts')[0],
        );
        $this->assertSame(1, MeetingBlock::query()->count());
    }

    public function test_validation_preview_returns_versioned_policy_findings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $room = Room::create(['name' => 'POLICY-101', 'is_active' => true]);
        $section = $this->createSection($term);

        $this->actingAs($admin)
            ->postJson(route('aop.schedule.calendar.preview', $term), [
                'section_id' => $section->id,
                'type' => 'LECTURE',
                'days' => ['Mon'],
                'starts_at' => '13:00',
                'ends_at' => '14:00',
                'room_id' => $room->id,
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('policy_findings.0.rule_id', 'class.one_day_evening_start')
            ->assertJsonPath('policy_findings.0.category', 'institutional_policy')
            ->assertJsonPath('policy_findings.0.severity', 'warning')
            ->assertJsonPath('policy_findings.0.policy_version', 'compatibility-v1')
            ->assertJsonStructure(['policy_findings' => [[
                'rule_id', 'category', 'severity', 'affected_resources', 'explanation',
                'remediation_url', 'policy_version',
            ]]])
            ->assertJsonPath('warnings.0', fn ($warning) => is_string($warning) && $warning !== '');
    }

    public function test_compatibility_policy_preserves_meeting_window_rules(): void
    {
        $term = $this->createActiveTerm();
        $rules = app(ScheduleRuleWarningService::class);
        $fixtures = [
            [["Mon", "Wed"], '13:00', '14:00', 'class.two_day_afternoon_start'],
            [['Mon'], '07:30', '08:30', 'class.standard_opening_time'],
            [['Tue'], '21:00', '23:00', 'class.latest_closing_time'],
            [['Mon'], '16:00', '17:30', 'class.faculty_meeting_window'],
            [['Thu'], '16:00', '19:00', 'class.day_course_closing_time'],
        ];

        foreach ($fixtures as [$days, $startsAt, $endsAt, $expectedRule]) {
            $ruleIds = collect($rules->findingsForBlock(
                $days,
                $startsAt,
                $endsAt,
                'LECTURE',
                $term,
            ))->pluck('ruleId')->all();

            $this->assertContains($expectedRule, $ruleIds);
        }
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

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update', $term), [
            'blockId' => $target->id,
            'starts_at' => '11:30',
            'ends_at' => '12:30',
            'revision' => $this->revision($target),
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

        $response = $this->actingAs($admin)->postJson(route('aop.schedule.calendar.update', $term), [
            'blockId' => $block->id,
            'starts_at' => '10:00',
            'ends_at' => '11:15',
            'revision' => $this->revision($block),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $block->refresh();
        $this->assertSame('10:00', substr((string) $block->starts_at, 0, 5));
        $this->assertSame('11:15', substr((string) $block->ends_at, 0, 5));
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'meeting_block_id' => $block->id,
            'user_id' => $admin->id,
            'action' => 'updated',
        ]);
    }

    public function test_calendar_rejects_a_stale_event_revision_without_overwriting_newer_work(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $section = $this->createSection($term);
        $room = Room::create(['name' => 'SCI-402', 'is_active' => true]);
        $block = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Fri'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);
        $staleRevision = $this->revision($block);
        $block->update(['starts_at' => '10:00', 'ends_at' => '11:00']);

        $this->actingAs($admin)
            ->postJson(route('aop.schedule.calendar.update', $term), [
                'blockId' => $block->id,
                'starts_at' => '12:00',
                'ends_at' => '13:00',
                'revision' => $staleRevision,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.revision.0', 'This event changed after the calendar loaded. Refresh the calendar and try again.');

        $this->assertSame('10:00', substr((string) $block->fresh()->starts_at, 0, 5));
    }

    public function test_calendar_cannot_bypass_configured_institutional_enforcement(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['enforce_schedule_blocks' => true]);
        $section = $this->createSection($term);
        $room = Room::create(['name' => 'SCI-403', 'is_active' => true]);
        $block = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Fri'],
            'starts_at' => '17:00',
            'ends_at' => '19:50',
            'room_id' => $room->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('aop.schedule.calendar.update', $term), [
                'blockId' => $block->id,
                'starts_at' => '12:00',
                'ends_at' => '13:00',
                'revision' => $this->revision($block),
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['errors' => ['schedule_rules']]);

        $this->assertSame('17:00', substr((string) $block->fresh()->starts_at, 0, 5));
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
            'term' => $term,
            'missing' => 'meeting_blocks',
        ]));

        $response->assertOk();
        $response->assertSeeText($missing->section_code);
        $response->assertDontSeeText($withBlock->section_code);
    }

    public function test_online_sections_are_excluded_from_missing_meeting_block_checks(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();

        $online = $this->createSection($term, [
            'section_code' => 'ONLINE-01',
            'modality' => 'ONLINE',
        ]);
        $inPerson = $this->createSection($term, [
            'section_code' => 'CAMPUS-01',
            'modality' => 'IN_PERSON',
        ]);

        $this->actingAs($admin)->get(route('aop.schedule.sections.index', [
            'term' => $term,
            'missing' => 'meeting_blocks',
        ]))
            ->assertOk()
            ->assertSeeText($inPerson->section_code)
            ->assertDontSeeText($online->section_code);

        $this->actingAs($admin)->get(route('aop.schedule.readiness.index', $term))
            ->assertOk()
            ->assertViewHas('sectionsMissingMeetingBlocks', function ($sections) use ($online, $inPerson) {
                return $sections->contains('id', $inPerson->id)
                    && ! $sections->contains('id', $online->id);
            });
    }

    public function test_online_section_without_meeting_blocks_can_be_exported(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();
        $instructor = Instructor::create([
            'name' => 'Online Instructor',
            'email' => 'online@example.test',
            'is_full_time' => false,
            'is_active' => true,
        ]);
        $section = $this->createSection($term, [
            'section_code' => 'ONLINE-PUBLISH-01',
            'modality' => 'ONLINE',
            'instructor_id' => $instructor->id,
        ]);
        $section->offering->catalogCourse->update([
            'contact_hours_per_week' => 3,
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.reports.exportRegistrar', $term));

        $response->assertOk()->assertDownload('aop_2026sp_registrar_schedule.csv');
        $this->assertStringContainsString('ONLINE-PUBLISH-01', $response->streamedContent());
        $this->assertStringContainsString('Online Instruction', $response->streamedContent());
    }

    public function test_legacy_publish_endpoint_downloads_current_csv_without_lock_or_override(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm();

        $this->createSection($term, [
            'section_code' => 'PUB-BLOCK-01',
            'instructor_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('aop.schedule.publish.store', $term));

        $response->assertOk()->assertDownload('aop_2026sp_registrar_schedule.csv');
        $this->assertStringContainsString('PUB-BLOCK-01', $response->streamedContent());
        $this->assertDatabaseCount('schedule_publications', 0);
    }

    public function test_legacy_lock_action_clears_old_lock_and_opens_registrar_export(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = $this->createActiveTerm(['schedule_locked' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.lock', $term))
            ->assertRedirect(route('aop.schedule.reports.index', $term));

        $this->assertFalse((bool) $term->fresh()->schedule_locked);
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

    private function revision(MeetingBlock $block): int
    {
        return (int) ScheduledEvent::query()
            ->where('source_type', MeetingBlock::class)
            ->where('source_id', $block->id)
            ->value('revision');
    }
}
