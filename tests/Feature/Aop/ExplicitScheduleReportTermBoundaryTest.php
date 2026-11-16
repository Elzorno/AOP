<?php

namespace Tests\Feature\Aop;

use App\Models\LabTech;
use App\Models\Instructor;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitScheduleReportTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_report_and_exports_use_the_route_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $room = Room::query()->create(['name' => 'REPORT-101', 'is_active' => true]);
        $instructor = Instructor::query()->create([
            'name' => 'Report Instructor',
            'email' => 'report-instructor@example.test',
            'is_active' => true,
        ]);
        $labTech = LabTech::query()->create([
            'name' => 'Report Technician',
            'email' => 'report-tech@example.test',
            'weekly_hour_limit' => 20,
            'is_active' => true,
        ]);
        OpenLab::query()->create([
            'term_id' => $selected->id,
            'name' => 'Selected Report Lab',
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => ['Wed'],
            'starts_at' => '12:00',
            'ends_at' => '13:00',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.reports.index', $selected))
            ->assertOk()
            ->assertSee('SELECTED')
            ->assertSee('action="'.route('aop.schedule.reports.exportSelectedInstructor', $selected).'"', false)
            ->assertSee('name="instructor_id"', false)
            ->assertSee('action="'.route('aop.schedule.reports.exportSelectedRoom', $selected).'"', false)
            ->assertSee('name="room_id"', false)
            ->assertDontSee('onclick=', false);

        $registrar = $this->actingAs($admin)
            ->get(route('aop.schedule.reports.exportRegistrar', $selected));
        $registrar->assertOk()->assertDownload('aop_selected_registrar_schedule.csv');

        $roomExport = $this->actingAs($admin)
            ->get(route('aop.schedule.reports.exportRoom', [$selected, $room]));
        $roomExport->assertOk()->assertDownload('aop_SELECTED_room_report_101.csv');
        $this->assertStringContainsString('Selected Report Lab', $roomExport->streamedContent());

        $this->actingAs($admin)
            ->get(route('aop.schedule.reports.exportSelectedRoom', [
                'term' => $selected,
                'room_id' => $room->id,
            ]))
            ->assertOk()
            ->assertDownload('aop_SELECTED_room_report_101.csv');
        $this->actingAs($admin)
            ->get(route('aop.schedule.reports.exportSelectedInstructor', [
                'term' => $selected,
                'instructor_id' => $instructor->id,
            ]))
            ->assertOk()
            ->assertDownload('aop_SELECTED_instructor_report_instructor.csv');
    }

    public function test_legacy_report_get_redirects_to_the_users_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.reports.legacy'))
            ->assertRedirect(route('aop.schedule.reports.index', $selected));
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
