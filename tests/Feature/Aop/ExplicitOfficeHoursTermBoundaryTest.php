<?php

namespace Tests\Feature\Aop;

use App\Models\Instructor;
use App\Models\InstructorPreference;
use App\Models\InstructorTermLock;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitOfficeHoursTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_pages_and_writes_use_the_route_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        $instructor = $this->instructor();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('aop.schedule.officeHours.index', $selected))
            ->assertOk()
            ->assertSee('SELECTED');
        $this->actingAs($admin)
            ->get(route('aop.schedule.officeHours.show', [$selected, $instructor]))
            ->assertOk()
            ->assertSee('SELECTED');

        $this->actingAs($admin)
            ->post(route('aop.schedule.officeHours.blocks.store', [$selected, $instructor]), $this->blockPayload())
            ->assertRedirect(route('aop.schedule.officeHours.show', [$selected, $instructor]));
        $this->assertDatabaseHas('office_hour_blocks', [
            'term_id' => $selected->id,
            'instructor_id' => $instructor->id,
        ]);

        $this->actingAs($admin)
            ->put(route('aop.schedule.officeHours.preferences.upsert', [$selected, $instructor]), [
                'max_courses' => 4,
                'preferred_days' => ['Tue'],
            ])
            ->assertRedirect(route('aop.schedule.officeHours.show', [$selected, $instructor]));
        $this->assertDatabaseHas('instructor_preferences', [
            'term_id' => $selected->id,
            'instructor_id' => $instructor->id,
            'max_courses' => 4,
        ]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.officeHours.lock', [$selected, $instructor]))
            ->assertRedirect(route('aop.schedule.officeHours.show', [$selected, $instructor]));
        $this->assertTrue(InstructorTermLock::query()
            ->whereBelongsTo($selected)
            ->whereBelongsTo($instructor)
            ->firstOrFail()
            ->office_hours_locked);
    }

    public function test_cross_term_block_mutations_are_rejected_without_changes(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $instructor = $this->instructor();
        $block = OfficeHourBlock::query()->create([
            'term_id' => $first->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->put(route('aop.schedule.officeHours.blocks.update', [$second, $instructor, $block]), $this->blockPayload('13:00', '14:00'))
            ->assertStatus(400);
        $this->assertSame('09:00', substr((string) $block->refresh()->starts_at, 0, 5));

        $this->actingAs($admin)
            ->delete(route('aop.schedule.officeHours.blocks.destroy', [$second, $instructor, $block]))
            ->assertStatus(400);
        $this->assertDatabaseHas('office_hour_blocks', ['id' => $block->id]);
    }

    public function test_preference_clear_only_affects_the_route_term(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        $instructor = $this->instructor();
        foreach ([$first, $second] as $term) {
            InstructorPreference::query()->create([
                'term_id' => $term->id,
                'instructor_id' => $instructor->id,
                'max_courses' => 3,
            ]);
        }
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.officeHours.preferences.destroy', [$second, $instructor]))
            ->assertRedirect(route('aop.schedule.officeHours.show', [$second, $instructor]));

        $this->assertDatabaseHas('instructor_preferences', [
            'term_id' => $first->id,
            'instructor_id' => $instructor->id,
        ]);
        $this->assertDatabaseMissing('instructor_preferences', [
            'term_id' => $second->id,
            'instructor_id' => $instructor->id,
        ]);
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
            ->get(route('aop.schedule.officeHours.legacy'))
            ->assertRedirect(route('aop.schedule.officeHours.index', $selected));
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

    private function instructor(): Instructor
    {
        return Instructor::query()->create([
            'name' => 'Office Hours Instructor',
            'email' => 'office-hours@example.test',
            'is_active' => true,
        ]);
    }

    private function blockPayload(string $start = '11:00', string $end = '12:00'): array
    {
        return [
            'days' => ['Tue'],
            'starts_at' => $start,
            'ends_at' => $end,
        ];
    }
}
