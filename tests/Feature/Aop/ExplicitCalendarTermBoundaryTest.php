<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitCalendarTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_calendar_and_feed_render_from_route_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $block = $this->block($selected);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.calendar.index', $selected))
            ->assertOk()
            ->assertSee('SELECTED')
            ->assertSee('id="calendarStatus"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('tabindex="-1"', false)
            ->assertSee('id="calendarReload"', false)
            ->assertSee('focus a class meeting and press Enter')
            ->assertSee("data.errors && data.errors.revision", false)
            ->assertSee("info.revert()", false);
        $this->actingAs($admin)
            ->getJson(route('aop.schedule.calendar.events', $selected))
            ->assertOk()
            ->assertJsonFragment([
                'blockId' => $block->id,
                'url' => route('aop.schedule.sections.edit', [$selected, $block->section_id]),
            ]);
    }

    public function test_calendar_update_rejects_a_block_from_another_term_without_mutation(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $block = $this->block($first);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->postJson(route('aop.schedule.calendar.update', $second), [
                'blockId' => $block->id,
                'starts_at' => '13:00',
                'ends_at' => '14:00',
                'revision' => $this->revision($block),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.blockId.0', 'Meeting block does not belong to the requested term.');

        $this->assertSame('09:00', substr((string) $block->refresh()->starts_at, 0, 5));
    }

    public function test_legacy_get_redirects_to_the_users_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.calendar.legacy'))
            ->assertRedirect(route('aop.schedule.calendar.index', $selected));
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

    private function block(Term $term): MeetingBlock
    {
        $course = CatalogCourse::query()->create([
            'code' => 'CIS-'.$term->id,
            'title' => 'Calendar',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create(['term_id' => $term->id, 'catalog_course_id' => $course->id]);
        $section = Section::query()->create([
            'offering_id' => $offering->id,
            'section_code' => '01',
            'modality' => 'IN_PERSON',
        ]);
        $room = Room::query()->create(['name' => 'ROOM-'.$term->id, 'is_active' => true]);

        return MeetingBlock::query()->create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);
    }

    private function revision(MeetingBlock $block): int
    {
        return (int) ScheduledEvent::query()
            ->where('source_type', MeetingBlock::class)
            ->where('source_id', $block->id)
            ->value('revision');
    }
}
