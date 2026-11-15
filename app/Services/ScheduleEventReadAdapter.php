<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use Illuminate\Support\Collection;

/**
 * Presents type-specific detail records with schedule fields sourced from their linked event.
 * The adapted models are in-memory view objects and must never be saved.
 */
class ScheduleEventReadAdapter
{
    public function meetingBlocks(Collection $blocks): Collection
    {
        $blocks->loadMissing('scheduledEvent.room');

        return $blocks->each(fn (MeetingBlock $block) => $this->apply($block));
    }

    public function openLabs(Collection $openLabs): Collection
    {
        $openLabs->loadMissing('scheduledEvent.room');

        return $openLabs->each(fn (OpenLab $openLab) => $this->apply($openLab));
    }

    public function officeHours(Collection $blocks): Collection
    {
        $blocks->loadMissing('scheduledEvent.room');

        return $blocks->each(fn (OfficeHourBlock $block) => $this->apply($block));
    }

    private function apply(object $source): void
    {
        $event = $source->scheduledEvent;
        if (! $event) {
            return;
        }

        foreach (['days_json', 'starts_at', 'ends_at', 'room_id', 'notes'] as $attribute) {
            $source->setAttribute($attribute, $event->getAttribute($attribute));
        }
        if ($source instanceof MeetingBlock || $source instanceof OpenLab) {
            $source->setRelation('room', $event->room);
        }
    }
}
