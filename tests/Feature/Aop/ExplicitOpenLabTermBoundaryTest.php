<?php

namespace Tests\Feature\Aop;

use App\Models\LabTech;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitOpenLabTermBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_term_pages_render_and_store_uses_the_route_term(): void
    {
        $this->term('ACTIVE', true);
        $selected = $this->term('SELECTED', false);
        [$room, $labTech] = $this->resources();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('aop.schedule.openLabs.index', $selected))->assertOk();
        $this->actingAs($admin)->get(route('aop.schedule.openLabs.create', $selected))->assertOk();
        $this->actingAs($admin)
            ->post(route('aop.schedule.openLabs.store', $selected), $this->payload($room, $labTech))
            ->assertRedirect(route('aop.schedule.openLabs.index', $selected));

        $openLab = OpenLab::query()->firstOrFail();
        $this->assertSame($selected->id, $openLab->term_id);
        $this->actingAs($admin)
            ->get(route('aop.schedule.openLabs.edit', [$selected, $openLab]))
            ->assertOk();
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $selected->id,
            'section_id' => null,
            'user_id' => $admin->id,
            'action' => 'created_open_lab',
        ]);
    }

    public function test_cross_term_update_and_delete_are_rejected_without_mutation(): void
    {
        $first = $this->term('FIRST', true);
        $second = $this->term('SECOND', false);
        [$room, $labTech] = $this->resources();
        $openLab = OpenLab::query()->create(array_merge($this->payload($room, $labTech), [
            'term_id' => $first->id,
            'days_json' => ['Mon'],
        ]));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->put(route('aop.schedule.openLabs.update', [$second, $openLab]), $this->payload($room, $labTech, 'Changed'))
            ->assertNotFound();
        $this->assertSame('Open Lab', $openLab->refresh()->name);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.openLabs.destroy', [$second, $openLab]))
            ->assertNotFound();
        $this->assertDatabaseHas('open_labs', ['id' => $openLab->id]);
    }

    public function test_update_and_delete_keep_projection_and_audit_in_the_same_workflow(): void
    {
        $term = $this->term('CURRENT', true);
        [$room, $labTech] = $this->resources();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('aop.schedule.openLabs.store', $term), $this->payload($room, $labTech))
            ->assertRedirect(route('aop.schedule.openLabs.index', $term));

        $openLab = OpenLab::query()->firstOrFail();
        $this->assertDatabaseHas('scheduled_events', [
            'term_id' => $term->id,
            'source_type' => OpenLab::class,
            'source_id' => $openLab->id,
            'display_name' => 'Open Lab',
        ]);

        $this->actingAs($admin)
            ->put(route('aop.schedule.openLabs.update', [$term, $openLab]), $this->payload($room, $labTech, 'Updated Lab'))
            ->assertRedirect(route('aop.schedule.openLabs.index', $term));

        $this->assertDatabaseHas('scheduled_events', [
            'source_type' => OpenLab::class,
            'source_id' => $openLab->id,
            'display_name' => 'Updated Lab',
        ]);
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'user_id' => $admin->id,
            'action' => 'updated_open_lab',
        ]);

        $this->actingAs($admin)
            ->delete(route('aop.schedule.openLabs.destroy', [$term, $openLab]))
            ->assertRedirect(route('aop.schedule.openLabs.index', $term));

        $this->assertDatabaseMissing('open_labs', ['id' => $openLab->id]);
        $this->assertDatabaseMissing('scheduled_events', [
            'source_type' => OpenLab::class,
            'source_id' => $openLab->id,
        ]);
        $this->assertDatabaseHas('schedule_change_logs', [
            'term_id' => $term->id,
            'user_id' => $admin->id,
            'action' => 'deleted_open_lab',
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
            ->get(route('aop.schedule.openLabs.legacy'))
            ->assertRedirect(route('aop.schedule.openLabs.index', $selected));
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

    private function resources(): array
    {
        return [
            Room::query()->create(['name' => 'LAB-101', 'is_active' => true]),
            LabTech::query()->create([
                'name' => 'Lab Technician',
                'weekly_hour_limit' => 20,
                'is_active' => true,
            ]),
        ];
    }

    private function payload(Room $room, LabTech $labTech, string $name = 'Open Lab'): array
    {
        return [
            'name' => $name,
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days' => ['Mon'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ];
    }
}
