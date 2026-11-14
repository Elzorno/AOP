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

    public function test_linked_instructor_access_survives_an_unrelated_account_email(): void
    {
        $term = $this->activeTerm();
        $instructor = Instructor::create(['name' => 'Linked Instructor', 'email' => 'faculty@example.test', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'account@example.test', 'instructor_id' => $instructor->id]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertViewIs('aop.instructor_dashboard')
            ->assertViewHas('instructor', fn (Instructor $value) => $value->is($instructor));

        $this->actingAs($user)->post(route('aop.instructor_preferences.store'), [
            'max_courses' => 3,
            'preferred_days' => 'Mon, Wed',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('instructor_preferences', [
            'term_id' => $term->id,
            'instructor_id' => $instructor->id,
            'max_courses' => 3,
        ]);
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
