<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\LabTech;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitScheduledEventTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_pages_render_and_open_lab_event_uses_route_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $room = Room::query()->create(['name' => 'LAB-201', 'is_active' => true]);
        $labTech = LabTech::query()->create([
            'name' => 'Event Technician',
            'weekly_hour_limit' => 20,
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('aop.schedule.events.index', $selected))->assertOk();
        $this->actingAs($admin)->get(route('aop.schedule.events.create', $selected))->assertOk();
        $this->actingAs($admin)
            ->post(route('aop.schedule.events.store', $selected), [
                'event_type' => 'open_lab',
                'name' => 'Selected Term Lab',
                'room_id' => $room->id,
                'lab_tech_id' => $labTech->id,
                'days' => ['Tue'],
                'starts_at' => '11:00',
                'ends_at' => '12:00',
            ])
            ->assertRedirect(route('aop.schedule.events.index', $selected));

        $this->assertDatabaseHas('open_labs', [
            'term_id' => $selected->id,
            'name' => 'Selected Term Lab',
        ]);
    }

    public function test_class_event_rejects_a_section_from_another_term_without_mutation(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $course = CatalogCourse::query()->create(['code' => 'CIS-301', 'title' => 'Events', 'is_active' => true]);
        $offering = Offering::query()->create(['term_id' => $first->id, 'catalog_course_id' => $course->id]);
        $section = Section::query()->create([
            'offering_id' => $offering->id,
            'section_code' => '01',
            'modality' => 'ONLINE',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.events.store', $second), [
                'event_type' => 'class',
                'section_id' => $section->id,
                'category' => 'LECTURE',
                'days' => ['Mon'],
                'starts_at' => '08:00',
                'ends_at' => '09:00',
            ])
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, MeetingBlock::query()->count());
        $this->assertSame(0, OpenLab::query()->count());
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
            ->get(route('aop.schedule.events.legacy'))
            ->assertRedirect(route('aop.schedule.events.index', $selected));
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
}
