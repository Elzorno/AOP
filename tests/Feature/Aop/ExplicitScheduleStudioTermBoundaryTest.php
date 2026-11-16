<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Offering;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitScheduleStudioTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_studio_renders_from_the_route_term(): void
    {
        $active = $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $this->offering($active, 'ACTIVE-101');
        $this->offering($selected, 'SELECTED-101');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.home', $selected))
            ->assertOk()
            ->assertSee('SELECTED-101')
            ->assertSee('SELECTED term');
    }

    public function test_distribution_preview_uses_the_route_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $offering = $this->offering($selected, 'SELECTED-101');
        $section = $offering->sections()->create([
            'section_code' => '01',
            'modality' => 'IN_PERSON',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson(route('aop.schedule.distribution.preview', [
                'term' => $selected,
                'section_id' => $section->id,
                'days' => ['Mon'],
                'starts_at' => '09:00',
                'ends_at' => '10:00',
            ]))
            ->assertOk()
            ->assertJsonStructure(['base', 'impact'])
            ->assertJsonPath('base.policy_version', 'compatibility-v1')
            ->assertJsonPath('impact.policy_version', 'compatibility-v1');
    }

    public function test_distribution_preview_models_a_block_on_the_existing_section(): void
    {
        $selected = $this->term('SELECTED', true);
        $offering = $this->offering($selected, 'SELECTED-101');
        $section = $offering->sections()->create([
            'section_code' => '01',
            'modality' => 'IN_PERSON',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson(route('aop.schedule.distribution.preview', [
                'term' => $selected,
                'section_id' => $section->id,
                'days' => ['Fri'],
                'starts_at' => '09:00',
                'ends_at' => '10:00',
            ]))
            ->assertOk()
            ->assertJsonPath('impact.totals.sections', 1)
            ->assertJsonPath('impact.totals.sections_with_friday', 1)
            ->assertJsonPath('impact.friday_pct', 100);
    }

    public function test_distribution_preview_rejects_a_section_from_another_term(): void
    {
        $selected = $this->term('SELECTED', true);
        $other = $this->term('OTHER', false);
        $section = $this->offering($other, 'OTHER-101')->sections()->create([
            'section_code' => '01',
            'modality' => 'IN_PERSON',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson(route('aop.schedule.distribution.preview', [
                'term' => $selected,
                'section_id' => $section->id,
                'days' => ['Fri'],
                'starts_at' => '09:00',
                'ends_at' => '10:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.section_id.0', 'Section does not belong to the requested term.');
    }

    public function test_section_editor_exposes_accessible_normalized_distribution_preview(): void
    {
        $selected = $this->term('SELECTED', true);
        $offering = $this->offering($selected, 'SELECTED-101');
        $section = $offering->sections()->create([
            'section_code' => '01',
            'modality' => 'IN_PERSON',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.sections.edit', [$selected, $section]))
            ->assertOk()
            ->assertSee('id="distPreviewPanel"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('i.findings', false)
            ->assertSee('Distribution preview is temporarily unavailable');
    }

    public function test_legacy_get_preserves_query_and_redirects_to_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.home.legacy', ['issue' => 'attention']))
            ->assertRedirect(route('aop.schedule.home', [
                'term' => $selected,
                'issue' => 'attention',
            ]));
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
            'title' => $code,
            'is_active' => true,
        ]);

        return Offering::query()->create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);
    }
}
