<?php

namespace App\Services;

use App\Data\Scheduling\MeetingBlockCommand;
use App\Data\Scheduling\OfficeHourCommand;
use App\Data\Scheduling\OpenLabCommand;
use App\Models\InstructorTermLock;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use App\Policies\SchedulePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TermScheduleCloneService
{
    public function __construct(
        private readonly MeetingBlockMutationService $meetingBlocks,
        private readonly OfficeHourMutationService $officeHours,
        private readonly OpenLabMutationService $openLabs,
        private readonly SectionInstructorAssignmentService $sections,
    ) {}

    public function cloneToDraft(Term $sourceTerm): Term
    {
        $timestamp = now();
        $result = $this->cloneIntoFreshTerm($sourceTerm, [
            'code' => $sourceTerm->code.'-draft-'.$timestamp->timestamp,
            'name' => $sourceTerm->name.' (Draft '.$timestamp->format('Y-m-d H:i').')',
            'starts_on' => $sourceTerm->starts_on?->format('Y-m-d'),
            'ends_on' => $sourceTerm->ends_on?->format('Y-m-d'),
            'weeks_in_term' => $sourceTerm->weeks_in_term,
            'slot_minutes' => $sourceTerm->slot_minutes,
            'buffer_minutes' => $sourceTerm->buffer_minutes,
            'schedule_policy_version' => $sourceTerm->schedule_policy_version
                ?? SchedulePolicy::COMPATIBILITY_V1,
            'schedule_timezone' => $sourceTerm->schedule_timezone ?? 'UTC',
        ], true, true);

        return $result['term'];
    }

    public function cloneIntoFreshTerm(Term $sourceTerm, array $targetTermData, bool $copyInstructorAssignments = false, bool $copyOfficeHours = false): array
    {
        return DB::transaction(function () use ($sourceTerm, $targetTermData, $copyInstructorAssignments, $copyOfficeHours) {
            $targetTerm = Term::create([
                'code' => $targetTermData['code'],
                'name' => $targetTermData['name'],
                'starts_on' => $targetTermData['starts_on'] ?? null,
                'ends_on' => $targetTermData['ends_on'] ?? null,
                'weeks_in_term' => $targetTermData['weeks_in_term'],
                'slot_minutes' => $targetTermData['slot_minutes'],
                'buffer_minutes' => $targetTermData['buffer_minutes'],
                'schedule_policy_version' => $targetTermData['schedule_policy_version']
                    ?? $sourceTerm->schedule_policy_version
                    ?? SchedulePolicy::COMPATIBILITY_V1,
                'schedule_timezone' => $targetTermData['schedule_timezone']
                    ?? $sourceTerm->schedule_timezone
                    ?? 'UTC',
                'allowed_hours_json' => $sourceTerm->allowed_hours_json,
                'enforce_schedule_blocks' => $sourceTerm->enforce_schedule_blocks,
                'is_active' => false,
                'status' => 'draft',
                'schedule_locked' => false,
                'schedule_locked_at' => null,
                'schedule_locked_by_user_id' => null,
            ]);

            $this->assertTargetTermIsClean($targetTerm);

            $offeringsCopied = 0;
            $sectionsCopied = 0;
            $meetingBlocksCopied = 0;
            $openLabsCopied = 0;

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
                ]);
                $offeringsCopied++;

                foreach ($sourceOffering->sections->sortBy('id') as $sourceSection) {
                    $targetSection = $this->sections->createSection($targetTerm, $targetOffering, [
                        'section_code' => $sourceSection->section_code,
                        'instructor_id' => $copyInstructorAssignments
                            ? ($sourceSection->instructor_is_staff ? Section::STAFF_ASSIGNMENT : $sourceSection->instructor_id)
                            : null,
                        'modality' => $sourceSection->modality?->value ?? (string) $sourceSection->modality,
                        'notes' => $sourceSection->notes,
                        'section_capacity' => $sourceSection->section_capacity,
                        'enrolled_count' => $sourceSection->enrolled_count,
                    ]);
                    $sectionsCopied++;

                    foreach ($sourceSection->meetingBlocks->sortBy('id') as $sourceMeetingBlock) {
                        $this->meetingBlocks->create($targetTerm, $targetSection, MeetingBlockCommand::fromArray([
                            'type' => $sourceMeetingBlock->type?->value ?? (string) $sourceMeetingBlock->type,
                            'days' => $sourceMeetingBlock->days_json ?? [],
                            'starts_at' => $sourceMeetingBlock->starts_at,
                            'ends_at' => $sourceMeetingBlock->ends_at,
                            'room_id' => $sourceMeetingBlock->room_id,
                            'notes' => $sourceMeetingBlock->notes,
                        ]));
                        $meetingBlocksCopied++;
                    }
                }
            }

            $officeHoursCopied = 0;
            if ($copyOfficeHours) {
                $sourceOfficeHours = OfficeHourBlock::where('term_id', $sourceTerm->id)->get();
                foreach ($sourceOfficeHours as $sourceBlock) {
                    $this->officeHours->create($targetTerm, $sourceBlock->instructor, OfficeHourCommand::fromArray([
                        'days' => $sourceBlock->days_json,
                        'starts_at' => $sourceBlock->starts_at,
                        'ends_at' => $sourceBlock->ends_at,
                        'notes' => $sourceBlock->notes,
                    ]));
                    $officeHoursCopied++;
                }
            }

            $sourceOpenLabs = OpenLab::query()
                ->where('term_id', $sourceTerm->id)
                ->get();

            foreach ($sourceOpenLabs as $sourceOpenLab) {
                $this->openLabs->create($targetTerm, OpenLabCommand::fromArray([
                    'name' => $sourceOpenLab->name,
                    'room_id' => $sourceOpenLab->room_id,
                    'lab_tech_id' => $sourceOpenLab->lab_tech_id,
                    'days' => $sourceOpenLab->days_json ?? [],
                    'starts_at' => $sourceOpenLab->starts_at,
                    'ends_at' => $sourceOpenLab->ends_at,
                    'notes' => $sourceOpenLab->notes,
                ]));
                $openLabsCopied++;
            }

            return [
                'term' => $targetTerm,
                'counts' => [
                    'offerings' => $offeringsCopied,
                    'sections' => $sectionsCopied,
                    'meeting_blocks' => $meetingBlocksCopied,
                    'office_hours' => $officeHoursCopied,
                    'open_labs' => $openLabsCopied,
                ],
            ];
        }, 3);
    }

    private function assertTargetTermIsClean(Term $targetTerm): void
    {
        $hasOfferings = Offering::where('term_id', $targetTerm->id)->exists();
        $hasSections = Section::whereHas('offering', fn ($q) => $q->where('term_id', $targetTerm->id))->exists();
        $hasMeetingBlocks = MeetingBlock::whereHas('section.offering', fn ($q) => $q->where('term_id', $targetTerm->id))->exists();
        $hasOfficeHours = OfficeHourBlock::where('term_id', $targetTerm->id)->exists();
        $hasOpenLabs = OpenLab::where('term_id', $targetTerm->id)->exists();
        $hasInstructorLocks = InstructorTermLock::where('term_id', $targetTerm->id)->exists();
        $hasPublications = SchedulePublication::where('term_id', $targetTerm->id)->exists();

        if ($hasOfferings || $hasSections || $hasMeetingBlocks || $hasOfficeHours || $hasOpenLabs || $hasInstructorLocks || $hasPublications) {
            throw ValidationException::withMessages([
                'clone' => 'The target term is not clean. Cloning only runs into a brand-new or empty term.',
            ]);
        }
    }
}
