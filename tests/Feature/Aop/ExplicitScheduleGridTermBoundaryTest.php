<?php

namespace Tests\Feature\Aop;

use App\Models\LabTech;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitScheduleGridTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_room_grid_and_pdf_use_the_route_term(): void
    {
        $active = $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $room = Room::query()->create(['name' => 'GRID-101', 'is_active' => true]);
        $labTech = LabTech::query()->create([
            'name' => 'Grid Technician',
            'email' => 'grid-tech@example.test',
            'weekly_hour_limit' => 20,
            'is_active' => true,
        ]);
        $this->openLab($active, $room, $labTech, 'Active Lab');
        $this->openLab($selected, $room, $labTech, 'Selected Lab');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.grids.room', [$selected, $room]))
            ->assertOk()
            ->assertSeeText('Selected Lab')
            ->assertDontSeeText('Active Lab');

        $this->actingAs($admin)
            ->get(route('aop.schedule.grids.room.pdf', [$selected, $room]))
            ->assertOk()
            ->assertDownload('room-grid-SELECTED-grid-101.pdf');
    }

    public function test_legacy_grid_get_redirects_to_the_users_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.grids.legacy'))
            ->assertRedirect(route('aop.schedule.grids.index', $selected));
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

    private function openLab(Term $term, Room $room, LabTech $labTech, string $name): OpenLab
    {
        return OpenLab::query()->create([
            'term_id' => $term->id,
            'name' => $name,
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);
    }
}
