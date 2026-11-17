<?php

namespace Tests\Feature\Aop;

use App\Models\Instructor;
use App\Models\InstructorPreference;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorIdentityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_loads_summary_counts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('aop.dashboard')
            ->assertSee('Administrator')
            ->assertSee('Account Links');
    }

    public function test_admin_home_uses_private_working_term_without_relabeling_it_as_institutional_current_term(): void
    {
        $institutional = $this->activeTerm();
        $working = Term::create([
            'code' => '2027FA', 'name' => 'Fall 2027', 'is_active' => false,
            'status' => 'draft', 'weeks_in_term' => 15, 'slot_minutes' => 15,
            'buffer_minutes' => 10,
        ]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'selected_term_id' => $working->id,
        ]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Working term: '.$working->code)
            ->assertSee($working->code.' schedule')
            ->assertSee(route('aop.schedule.sections.index', $working), false)
            ->assertDontSee($institutional->code.' schedule');
    }

    public function test_linked_instructor_access_survives_an_unrelated_account_email(): void
    {
        $term = $this->activeTerm();
        $instructor = Instructor::create(['name' => 'Linked Instructor', 'email' => 'faculty@example.test', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'account@example.test', 'instructor_id' => $instructor->id]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertViewIs('aop.instructor_dashboard')
            ->assertViewHas('instructor', fn (Instructor $value) => $value->is($instructor))
            ->assertSee('Faculty workspace')
            ->assertSee('My Schedule')
            ->assertSee('Teaching Preferences')
            ->assertSee('Office Hours')
            ->assertDontSee('Account Links')
            ->assertDontSee('Manage Terms')
            ->assertDontSee('Lab Techs');

        $this->actingAs($user)->post(route('aop.instructor_preferences.store'), [
            'max_courses' => 3,
            'preferred_days' => ['Mon', 'Wed'],
            'unavailable_times_raw' => "Tuesday 8:00 AM–10:00 AM\nFriday after 2:00 PM",
        ])->assertRedirect(route('dashboard').'#preferences');

        $this->assertDatabaseHas('instructor_preferences', [
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'max_courses' => 3,
        ]);
        $this->assertSame(['Mon', 'Wed'], InstructorPreference::query()->firstOrFail()->preferred_days);
        $this->assertSame(
            ['Tuesday 8:00 AM–10:00 AM', 'Friday after 2:00 PM'],
            InstructorPreference::query()->firstOrFail()->unavailable_times
        );
    }

    public function test_linked_instructor_can_update_own_office_hours_from_faculty_workspace(): void
    {
        $term = $this->activeTerm();
        $instructor = Instructor::create(['name' => 'Office Hours Owner', 'is_active' => true]);
        $user = User::factory()->create(['is_admin' => false, 'instructor_id' => $instructor->id]);
        $block = OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);

        $this->actingAs($user)->put(route('instructor.officeHours.update', [$instructor, $block]), [
            'from_instructor_portal' => '1',
            'days' => ['Tue', 'Thu'],
            'starts_at' => '13:00',
            'ends_at' => '14:00',
            'notes' => 'Library consultation room',
        ])->assertRedirect(route('dashboard').'#office-hours');

        $block->refresh();
        $this->assertSame(['Tue', 'Thu'], $block->days_json);
        $this->assertSame('13:00', substr((string) $block->starts_at, 0, 5));
        $this->assertSame('Library consultation room', $block->notes);
    }

    public function test_invalid_faculty_office_hour_edit_preserves_the_editor_context(): void
    {
        $term = $this->activeTerm();
        $instructor = Instructor::create(['name' => 'Context Owner', 'is_active' => true]);
        $user = User::factory()->create(['is_admin' => false, 'instructor_id' => $instructor->id]);
        $block = OfficeHourBlock::create([
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'days_json' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ]);

        $this->actingAs($user)->from(route('dashboard').'#office-hours')
            ->put(route('instructor.officeHours.update', [$instructor, $block]), [
                'from_instructor_portal' => '1',
                'office_hour_block_id' => $block->id,
                'days' => ['Wed'],
                'starts_at' => '14:00',
                'ends_at' => '13:00',
                'notes' => 'Keep this draft',
            ])
            ->assertSessionHasErrors('ends_at')
            ->assertSessionHasInput('office_hour_block_id', (string) $block->id)
            ->assertSessionHasInput('notes', 'Keep this draft');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('open', false)
            ->assertSee('Keep this draft');
    }

    public function test_matching_email_does_not_grant_unlinked_instructor_access(): void
    {
        $this->activeTerm();
        $instructor = Instructor::create(['name' => 'Target', 'email' => 'same@example.test', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'same@example.test', 'instructor_id' => null]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertViewIs('aop.instructor_dashboard_empty');

        $this->actingAs($user)->post(route('aop.instructor_preferences.store'), [
            'max_courses' => 3,
        ])->assertForbidden();

        $this->actingAs($user)->post(route('instructor.officeHours.store', $instructor), [
            'days' => ['Mon'], 'starts_at' => '09:00', 'ends_at' => '10:00',
        ])->assertForbidden();
        $this->assertSame(0, InstructorPreference::count());
        $this->assertSame(0, OfficeHourBlock::count());
    }

    public function test_profile_email_change_cannot_switch_instructor_identity(): void
    {
        $first = Instructor::create(['name' => 'First', 'email' => 'first@example.test', 'is_active' => true]);
        $second = Instructor::create(['name' => 'Second', 'email' => 'second@example.test', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'first@example.test', 'instructor_id' => $first->id]);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $second->email,
        ])->assertRedirect('/profile');

        $this->assertSame($first->id, $user->refresh()->instructor_id);
    }

    public function test_one_instructor_cannot_be_linked_to_two_accounts(): void
    {
        $instructor = Instructor::create(['name' => 'Unique Link', 'is_active' => true]);
        User::factory()->create(['instructor_id' => $instructor->id]);

        $this->expectException(QueryException::class);
        User::factory()->create(['instructor_id' => $instructor->id]);
    }

    public function test_administrator_can_manage_links_but_cannot_duplicate_them(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $instructor = Instructor::create(['name' => 'Managed Link', 'is_active' => true]);

        $this->actingAs($admin)->get(route('aop.accounts.index'))->assertOk();
        $this->actingAs($admin)->put(route('aop.accounts.instructor.update', $firstUser), [
            'instructor_id' => $instructor->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($instructor->id, $firstUser->refresh()->instructor_id);

        $this->actingAs($admin)->from(route('aop.accounts.index'))
            ->put(route('aop.accounts.instructor.update', $secondUser), [
                'instructor_id' => $instructor->id,
            ])->assertSessionHasErrors('instructor_id');
        $this->assertNull($secondUser->refresh()->instructor_id);
    }

    public function test_non_administrator_cannot_manage_account_links(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('aop.accounts.index'))->assertForbidden();
        $this->actingAs($user)->put(route('aop.accounts.instructor.update', $user), [
            'instructor_id' => null,
        ])->assertForbidden();
    }

    private function activeTerm(): Term
    {
        return Term::create([
            'code' => '2027SP', 'name' => 'Spring 2027', 'is_active' => true,
            'status' => 'draft', 'weeks_in_term' => 15, 'slot_minutes' => 15,
            'buffer_minutes' => 10,
        ]);
    }
}
