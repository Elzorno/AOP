<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Offering;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitLegacyScheduleActionTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_publish_export_uses_the_route_term(): void
    {
        $active = $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $this->section($active, 'ACTIVE-101');
        $this->section($selected, 'SELECTED-101');
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->post(route('aop.schedule.publish.store', $selected));

        $response->assertOk()->assertDownload('aop_selected_registrar_schedule.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('SELECTED-101', $csv);
        $this->assertStringNotContainsString('ACTIVE-101', $csv);
    }

    public function test_retired_lock_action_only_clears_the_route_term(): void
    {
        $active = $this->term('ACTIVE', true, true);
        $selected = $this->term('SELECTED', false, true);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.lock', $selected))
            ->assertRedirect(route('aop.schedule.reports.index', $selected));

        $this->assertTrue((bool) $active->refresh()->schedule_locked);
        $this->assertFalse((bool) $selected->refresh()->schedule_locked);
    }

    public function test_legacy_publish_get_redirects_to_the_users_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.publish.legacy'))
            ->assertRedirect(route('aop.schedule.publish.index', $selected));
    }

    private function term(string $code, bool $active, bool $locked = false): Term
    {
        return Term::query()->create([
            'code' => $code,
            'name' => $code.' term',
            'is_active' => $active,
            'schedule_locked' => $locked,
            'weeks_in_term' => 15,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
        ]);
    }

    private function section(Term $term, string $code): Section
    {
        $course = CatalogCourse::query()->create([
            'code' => $code,
            'title' => $code,
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);

        return Section::query()->create([
            'offering_id' => $offering->id,
            'section_code' => '01',
            'modality' => 'ONLINE',
            'instructor_is_staff' => true,
        ]);
    }
}
