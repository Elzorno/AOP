<?php

namespace App\Services;

use App\Models\InstructorTermLock;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TermScheduleCloneService
{
    public function cloneIntoFreshTerm(Term $sourceTerm, array $targetTermData, bool $copyInstructorAssignments = false): array
    {
        return DB::transaction(function () use ($sourceTerm, $targetTermData, $copyInstructorAssignments) {
            $targetTerm = Term::create([
                'code' => $targetTermData['code'],
                'name' => $targetTermData['name'],
                'starts_on' => $targetTermData['starts_on'] ?? null,
                'ends_on' => $targetTermData['ends_on'] ?? null,
                'weeks_in_term' => $targetTermData['weeks_in_term'],
                'slot_minutes' => $targetTermData['slot_minutes'],
                'buffer_minutes' => $targetTermData['buffer_minutes'],
                'allowed_hours_json' => $sourceTerm->allowed_hours_json,
                'is_active' => false,
                'schedule_locked' => false,
                'schedule_locked_at' => null,
                'schedule_locked_by_user_id' => null,
            ]);

            $this->assertTargetTermIsClean($targetTerm);

            $offeringsCopied = 0;
            $sectionsCopied = 0;
            $meetingBlocksCopied = 0;

            $sourceOfferings = Offering::query()
                ->with(['sections.meetingBlocks'])
                ->where('term_id', $sourceTerm->id)
                ->orderBy('id')
                ->get();

            foreach ($sourceOfferings as $sourceOffering) {
                $targetOffering = Offering::create([
                    'term_id' => $targetTerm->id,
                    'catalog_course_id' => $sourceOffering->catalog_course_id,
                    'delivery_method' => $sourceOffering->delivery_method,
                    'notes' => $sourceOffering->notes,
                    'prereq_override' => $sourceOffering->prereq_override,
                    'coreq_override' => $sourceOffering->coreq_override,
                    'default_syllabus_block_set_json' => $sourceOffering->default_syllabus_block_set_json,
                ]);
                $offeringsCopied++;

                foreach ($sourceOffering->sections->sortBy('id') as $sourceSection) {
                    $targetSection = Section::create([
                        'offering_id' => $targetOffering->id,
                        'section_code' => $sourceSection->section_code,
                        'instructor_id' => $copyInstructorAssignments ? $sourceSection->instructor_id : null,
                        'modality' => $sourceSection->modality?->value ?? (string) $sourceSection->modality,
                        'notes' => $sourceSection->notes,
                    ]);
                    $sectionsCopied++;

                    foreach ($sourceSection->meetingBlocks->sortBy('id') as $sourceMeetingBlock) {
                        MeetingBlock::create([
                            'section_id' => $targetSection->id,
                            'type' => $sourceMeetingBlock->type?->value ?? (string) $sourceMeetingBlock->type,
                            'days_json' => $sourceMeetingBlock->days_json ?? [],
                            'starts_at' => $sourceMeetingBlock->starts_at,
                            'ends_at' => $sourceMeetingBlock->ends_at,
                            'room_id' => $sourceMeetingBlock->room_id,
                            'notes' => $sourceMeetingBlock->notes,
                        ]);
                        $meetingBlocksCopied++;
                    }
                }
            }

            return [
                'term' => $targetTerm,
                'counts' => [
                    'offerings' => $offeringsCopied,
                    'sections' => $sectionsCopied,
                    'meeting_blocks' => $meetingBlocksCopied,
                ],
            ];
        });
    }

    private function assertTargetTermIsClean(Term $targetTerm): void
    {
        $hasOfferings = Offering::where('term_id', $targetTerm->id)->exists();
        $hasSections = Section::whereHas('offering', fn($q) => $q->where('term_id', $targetTerm->id))->exists();
        $hasMeetingBlocks = MeetingBlock::whereHas('section.offering', fn($q) => $q->where('term_id', $targetTerm->id))->exists();
        $hasOfficeHours = OfficeHourBlock::where('term_id', $targetTerm->id)->exists();
        $hasInstructorLocks = InstructorTermLock::where('term_id', $targetTerm->id)->exists();
        $hasPublications = SchedulePublication::where('term_id', $targetTerm->id)->exists();

        if ($hasOfferings || $hasSections || $hasMeetingBlocks || $hasOfficeHours || $hasInstructorLocks || $hasPublications) {
            throw ValidationException::withMessages([
                'clone' => 'The target term is not clean. Cloning only runs into a brand-new or empty term.',
            ]);
        }
    }
}
