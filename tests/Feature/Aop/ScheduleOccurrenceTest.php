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

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('aop.schedule.readiness.index', $term))
            ->assertOk()
            ->assertViewHas('instructionalMinutes', fn (array $rows) => $rows[0]['scheduled_minutes'] === 180);
    }
}
