<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Offering;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitOfferingTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_offering_write_uses_route_term_instead_of_global_active_term(): void
    {
        $active = $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $course = CatalogCourse::query()->create([
            'code' => 'CIS-101',
            'title' => 'Foundations',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.offerings.store', $selected), [
                'catalog_course_id' => $course->id,
            ])
            ->assertRedirect(route('aop.schedule.offerings.index', $selected));

        $this->assertDatabaseHas('offerings', [
            'term_id' => $selected->id,
            'catalog_course_id' => $course->id,
        ]);
        $this->assertDatabaseMissing('offerings', [
            'term_id' => $active->id,
            'catalog_course_id' => $course->id,
        ]);
    }

    public function test_cross_term_offering_delete_is_rejected(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $course = CatalogCourse::query()->create([
            'code' => 'CIS-102',
            'title' => 'Systems',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'term_id' => $first->id,
            'catalog_course_id' => $course->id,
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.offerings.destroy', [$second, $offering]))
            ->assertNotFound();

        $this->assertDatabaseHas('offerings', ['id' => $offering->id]);
    }

    public function test_legacy_offering_get_redirects_to_users_selected_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $selected->id,
        ]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.offerings.legacy'))
            ->assertRedirect(route('aop.schedule.offerings.index', $selected));
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
