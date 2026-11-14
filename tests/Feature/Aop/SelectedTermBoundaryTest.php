<?php

namespace Tests\Feature\Aop;

use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelectedTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_select_a_non_active_term_without_changing_the_global_active_term(): void
    {
        $active = Term::query()->create($this->termData('ACTIVE', true));
        $selected = Term::query()->create($this->termData('SELECTED', false));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.terms.schedule.select', $selected))
            ->assertRedirect(route('aop.schedule.offerings.index', $selected));

        $this->assertSame($selected->id, $admin->refresh()->selected_term_id);
        $this->assertTrue($active->refresh()->is_active);
    }

    public function test_each_admin_has_an_independent_selected_term(): void
    {
        $firstTerm = Term::query()->create($this->termData('FIRST', true));
        $secondTerm = Term::query()->create($this->termData('SECOND', false));
        $firstAdmin = User::factory()->create(['is_admin' => true]);
        $secondAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($firstAdmin)->post(route('aop.terms.schedule.select', $firstTerm));
        $this->actingAs($secondAdmin)->post(route('aop.terms.schedule.select', $secondTerm));

        $this->assertSame($firstTerm->id, $firstAdmin->refresh()->selected_term_id);
        $this->assertSame($secondTerm->id, $secondAdmin->refresh()->selected_term_id);
    }

    public function test_non_admin_cannot_select_an_admin_schedule_term(): void
    {
        $term = Term::query()->create($this->termData('TERM', true));
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('aop.terms.schedule.select', $term))
            ->assertForbidden();

        $this->assertNull($user->refresh()->selected_term_id);
    }

    private function termData(string $code, bool $active): array
    {
        return [
            'code' => $code,
            'name' => $code.' term',
            'is_active' => $active,
            'weeks_in_term' => 15,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
        ];
    }
}
