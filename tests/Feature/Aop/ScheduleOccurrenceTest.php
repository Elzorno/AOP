<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermScheduleException;
use App\Models\User;
use App\Services\ScheduleOccurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleOccurrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_occurrences_honor_term_dates_timezone_holidays_cancellations_and_makeups(): void
    {
        $term = Term::query()->create([
            'code' => 'DATES',
            'name' => 'Date-aware term',
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-01-18',
            'weeks_in_term' => 2,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
            'schedule_timezone' => 'America/New_York',
        ]);
        $course = CatalogCourse::query()->create([
            'code' => 'DATE-101',
            'title' => 'Date-aware scheduling',
            'lecture_hours_per_week' => 3,
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);
        $section = Section::query()->create([
            'offering_id' => $offering->id,
            'section_code' => '01',
            'modality' => 'IN_PERSON',
        ]);
        $room = Room::query()->create(['name' => 'DATE-ROOM', 'is_active' => true]);
        $block = MeetingBlock::query()->create([
            'section_id' => $section->id,
            'type' => 'LECTURE',
            'days_json' => ['Mon', 'Wed'],
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room_id' => $room->id,
        ]);
        $event = ScheduledEvent::query()->findOrFail($block->scheduled_event_id);

        TermScheduleException::query()->create([
            'term_id' => $term->id,
            'occurs_on' => '2026-01-05',
            'exception_type' => TermScheduleException::TYPE_HOLIDAY,
            'notes' => 'Campus closed',
        ]);
        TermScheduleException::query()->create([
            'term_id' => $term->id,
            'scheduled_event_id' => $event->id,
            'occurs_on' => '2026-01-07',
            'exception_type' => TermScheduleException::TYPE_CANCELLATION,
        ]);
        TermScheduleException::query()->create([
            'term_id' => $term->id,
            'scheduled_event_id' => $event->id,
            'occurs_on' => '2026-01-09',
            'exception_type' => TermScheduleException::TYPE_MAKEUP,
        ]);

        $dates = app(ScheduleOccurrenceService::class)
            ->datesForEvent($term->fresh(), $event)
            ->map->toDateString()
            ->all();

        $this->assertSame(['2026-01-09', '2026-01-12', '2026-01-14'], $dates);
        $this->assertSame('America/New_York', $term->fresh()->schedule_timezone);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->get(route('aop.schedule.readiness.index', $term))
            ->assertOk()
            ->assertViewHas('instructionalMinutes', fn (array $rows) => $rows[0]['scheduled_minutes'] === 180
                && $rows[0]['scheduled_occurrences'] === 3
                && $rows[0]['required_minutes'] === 300)
            ->assertViewHas('policyFindings', fn (array $findings) => collect($findings)->contains(
                fn (array $finding) => $finding['rule_id'] === 'readiness.instructional_minutes'
                    && str_contains($finding['explanation'], '180 scheduled occurrence minutes')
                    && str_contains($finding['explanation'], '300 required minutes')
                    && $finding['policy_version'] === 'compatibility-v1'
            ))
            ->assertSee('Policy findings')
            ->assertSee('compatibility-v1')
            ->assertSee('3 occurrences');

        $this->actingAs($admin)
            ->post(route('aop.terms.scheduleExceptions.store', $term), [
                'exception_type' => TermScheduleException::TYPE_HOLIDAY,
                'occurs_on' => '2026-01-06',
                'notes' => 'Administrative closure',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $createdException = TermScheduleException::query()
            ->where('notes', 'Administrative closure')
            ->firstOrFail();

        $otherTerm = Term::query()->create([
            'code' => 'OTHER',
            'name' => 'Other term',
            'weeks_in_term' => 2,
            'slot_minutes' => 30,
            'buffer_minutes' => 10,
        ]);
        $otherEvent = ScheduledEvent::query()->create([
            'term_id' => $otherTerm->id,
            'event_type' => ScheduledEvent::TYPE_OPEN_LAB,
            'source_type' => 'TestOtherSource',
            'source_id' => 999,
            'display_name' => 'Other event',
            'days_json' => ['Tue'],
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        $this->actingAs($admin)
            ->post(route('aop.terms.scheduleExceptions.store', $term), [
                'exception_type' => TermScheduleException::TYPE_CANCELLATION,
                'occurs_on' => '2026-01-08',
                'scheduled_event_id' => $otherEvent->id,
            ])
            ->assertSessionHasErrors('scheduled_event_id');
        $this->assertDatabaseMissing('term_schedule_exceptions', [
            'term_id' => $term->id,
            'scheduled_event_id' => $otherEvent->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('aop.terms.scheduleExceptions.destroy', [$term, $createdException]))
            ->assertRedirect();
        $this->assertDatabaseMissing('term_schedule_exceptions', ['id' => $createdException->id]);
        $this->actingAs($admin)->get(route('aop.terms.edit', $term))
            ->assertOk()
            ->assertSee('Schedule Timezone')
            ->assertSee('Schedule Exceptions');
    }
}
