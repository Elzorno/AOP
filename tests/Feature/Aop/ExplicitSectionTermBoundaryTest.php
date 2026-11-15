<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\ScheduleChangeLog;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitSectionTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_section_directory_create_and_edit_pages_render(): void
    {
        $term = $this->term('SELECTED', false);
        $section = $this->section($term, 'CIS-200');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.sections.index', $term))
            ->assertOk()
            ->assertSee($section->section_code);
        $this->actingAs($admin)
            ->get(route('aop.schedule.sections.create', $term))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('aop.schedule.sections.edit', [$term, $section]))
            ->assertOk();
    }

    public function test_section_write_uses_the_route_term_when_it_is_not_globally_active(): void
    {
        $active = $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $offering = $this->offering($selected, 'CIS-201');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.sections.store', $selected), [
                'offering_id' => $offering->id,
                'section_code' => '01',
                'modality' => 'ONLINE',
            ])
            ->assertRedirect(route('aop.schedule.sections.index', $selected));

        $this->assertDatabaseHas('sections', [
            'offering_id' => $offering->id,
            'section_code' => '01',
        ]);
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $selected->id,
            'section_id' => Section::query()->where('offering_id', $offering->id)->value('id'),
            'user_id' => $admin->id,
            'action' => 'section_created',
        ]);
        $this->assertSame(0, Section::query()->whereHas('offering', fn ($query) => $query->where('term_id', $active->id))->count());
    }

    public function test_cross_term_section_update_is_rejected(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $section = $this->section($first, 'CIS-202');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->put(route('aop.schedule.sections.update', [$second, $section]), [
                'section_code' => 'CHANGED',
                'modality' => 'ONLINE',
            ])
            ->assertNotFound();

        $this->assertSame('01', $section->refresh()->section_code);
    }

    public function test_meeting_block_must_belong_to_the_route_term_and_section(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $firstSection = $this->section($first, 'CIS-203');
        $secondSection = $this->section($second, 'CIS-204');
        $room = Room::query()->create(['name' => 'R-101', 'is_active' => true]);
        $block = MeetingBlock::query()->create([
            'section_id' => $firstSection->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.meetingBlocks.destroy', [$second, $secondSection, $block]))
            ->assertNotFound();

        $this->assertDatabaseHas('meeting_blocks', ['id' => $block->id]);
    }

    public function test_meeting_block_create_and_delete_share_transactional_audit_path(): void
    {
        $term = $this->term('AUDIT', true);
        $section = $this->section($term, 'CIS-207');
        $room = Room::query()->create(['name' => 'R-207', 'is_active' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.meetingBlocks.store', [$term, $section]), [
                'type' => 'LECTURE',
                'days' => ['Tue'],
                'starts_at' => '10:00',
                'ends_at' => '11:00',
                'room_id' => $room->id,
            ])
            ->assertRedirect(route('aop.schedule.sections.edit', [$term, $section]));

        $block = MeetingBlock::query()->firstOrFail();
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'meeting_block_id' => $block->id,
            'user_id' => $admin->id,
            'action' => 'created',
        ]);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.meetingBlocks.destroy', [$term, $section, $block]))
            ->assertRedirect(route('aop.schedule.sections.edit', [$term, $section]));

        $this->assertDatabaseMissing('meeting_blocks', ['id' => $block->id]);
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'meeting_block_id' => null,
            'user_id' => $admin->id,
            'action' => 'deleted',
        ]);
    }

    public function test_section_delete_removes_projections_but_preserves_null_linked_audit_history(): void
    {
        $term = $this->term('DELETE', true);
        $section = $this->section($term, 'CIS-213');
        $block = MeetingBlock::query()->create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Fri'],
            'starts_at' => '12:00',
            'ends_at' => '13:00',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.sections.destroy', [$term, $section]))
            ->assertRedirect(route('aop.schedule.sections.index', $term));

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('meeting_blocks', ['id' => $block->id]);
        $this->assertDatabaseMissing('scheduled_events', [
            'source_type' => MeetingBlock::class,
            'source_id' => $block->id,
        ]);
        $this->assertSame(1, ScheduleChangeLog::query()->where('action', 'deleted')->whereNull('section_id')->count());
        $this->assertSame(1, ScheduleChangeLog::query()->where('action', 'section_deleted')->whereNull('section_id')->count());
        $this->assertSame($section->id, ScheduleChangeLog::query()
            ->where('action', 'section_deleted')
            ->firstOrFail()
            ->payload_before['section_id']);
    }

    public function test_bulk_assignment_rejects_a_mixed_term_request_without_partial_updates(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $firstSection = $this->section($first, 'CIS-205');
        $secondSection = $this->section($second, 'CIS-206');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.sections.bulkAssign', $first), [
                'section_ids' => [$firstSection->id, $secondSection->id],
                'instructor_id' => Section::STAFF_ASSIGNMENT,
            ])
            ->assertNotFound();

        $this->assertFalse($firstSection->refresh()->instructor_is_staff);
        $this->assertFalse($secondSection->refresh()->instructor_is_staff);
    }

    public function test_instructor_reassignment_rejects_existing_office_hour_conflicts_atomically(): void
    {
        $term = $this->term('CONFLICT', true);
        $section = $this->section($term, 'CIS-208');
        MeetingBlock::query()->create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Tue'],
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        $instructor = Instructor::query()->create(['name' => 'Busy Instructor', 'is_active' => true]);
        OfficeHourBlock::query()->create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Tue'],
            'starts_at' => '10:30',
            'ends_at' => '11:30',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->put(route('aop.schedule.sections.update', [$term, $section]), [
                'section_code' => 'CHANGED',
                'instructor_id' => $instructor->id,
                'modality' => 'ONLINE',
            ])
            ->assertSessionHasErrors('instructor_id');

        $section->refresh();
        $this->assertSame('01', $section->section_code);
        $this->assertNull($section->instructor_id);
        $this->assertDatabaseCount('schedule_change_logs', 0);
    }

    public function test_bulk_assignment_rejects_conflicts_between_selected_sections_without_partial_updates(): void
    {
        $term = $this->term('BULK', true);
        $first = $this->section($term, 'CIS-209');
        $second = $this->section($term, 'CIS-210');
        foreach ([$first, $second] as $section) {
            MeetingBlock::query()->create([
                'section_id' => $section->id,
                'type' => 'LECTURE',
                'days_json' => ['Wed'],
                'starts_at' => '09:00',
                'ends_at' => '10:00',
            ]);
        }
        $instructor = Instructor::query()->create(['name' => 'Bulk Instructor', 'is_active' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.sections.bulkAssign', $term), [
                'section_ids' => [$first->id, $second->id],
                'instructor_id' => $instructor->id,
            ])
            ->assertSessionHasErrors('instructor_id');

        $this->assertNull($first->refresh()->instructor_id);
        $this->assertNull($second->refresh()->instructor_id);
        $this->assertDatabaseCount('schedule_change_logs', 0);
    }

    public function test_bulk_assignment_updates_projections_and_audits_every_section(): void
    {
        $term = $this->term('BULK-OK', true);
        $first = $this->section($term, 'CIS-211');
        $second = $this->section($term, 'CIS-212');
        foreach ([[$first, '09:00', '10:00'], [$second, '10:10', '11:10']] as [$section, $start, $end]) {
            MeetingBlock::query()->create([
                'section_id' => $section->id,
                'type' => 'LECTURE',
                'days_json' => ['Thu'],
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        }
        $instructor = Instructor::query()->create(['name' => 'Available Instructor', 'is_active' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.sections.bulkAssign', $term), [
                'section_ids' => [$first->id, $second->id],
                'instructor_id' => $instructor->id,
            ])
            ->assertRedirect(route('aop.schedule.sections.index', $term));

        $this->assertSame($instructor->id, $first->refresh()->instructor_id);
        $this->assertSame($instructor->id, $second->refresh()->instructor_id);
        $this->assertDatabaseCount('scheduled_event_staff_assignments', 2);
        $this->assertSame(2, ScheduleChangeLog::query()
            ->where('term_id', $term->id)
            ->where('user_id', $admin->id)
            ->where('action', 'section_updated')
            ->count());
    }

    private function term(string $code, bool $active): Term
    {
        return Term::query()->create([
            'code' => $code,
            'name' => $code.' term',
            'is_active' => $active,
            'weeks_in_term' => 15,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
        ]);
    }

    private function offering(Term $term, string $code): Offering
    {
        $course = CatalogCourse::query()->create([
            'code' => $code,
            'title' => $code.' course',
            'is_active' => true,
        ]);

        return Offering::query()->create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);
    }

    private function section(Term $term, string $code): Section
    {
        return Section::query()->create([
            'offering_id' => $this->offering($term, $code)->id,
            'section_code' => '01',
            'modality' => 'ONLINE',
        ]);
    }
}
