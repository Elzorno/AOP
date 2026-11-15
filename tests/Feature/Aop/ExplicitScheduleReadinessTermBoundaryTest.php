<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Offering;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitScheduleReadinessTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_readiness_uses_the_route_term(): void
    {
        $active = $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $activeSection = $this->section($active, 'ACTIVE-101');
        $selectedSection = $this->section($selected, 'SELECTED-101');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.readiness.index', $selected))
            ->assertOk()
            ->assertSee('SELECTED')
            ->assertViewHas('sectionsMissingInstructor', function ($sections) use ($activeSection, $selectedSection) {
                return $sections->contains('id', $selectedSection->id)
                    && ! $sections->contains('id', $activeSection->id);
            });
    }

    public function test_legacy_readiness_get_redirects_to_the_users_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.readiness.legacy'))
            ->assertRedirect(route('aop.schedule.readiness.index', $selected));
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
            'modality' => 'IN_PERSON',
        ]);
    }
}
