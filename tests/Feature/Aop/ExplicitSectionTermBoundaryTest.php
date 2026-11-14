<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Room;
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
