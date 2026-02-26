<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;

class MeetingBlockController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    private function ensureSectionInActiveTerm(Section $section): void
    {
        $term = $this->activeTermOrFail();
        $section->load('offering');
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');
    }

    public function store(Request $request, Section $section)
    {
        $this->ensureSectionInActiveTerm($section);

        $data = $request->validate([
            'type' => ['required','string'],
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => $data['type'],
            'days_json' => array_values($data['days']),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block added.');
    }

    public function update(Request $request, Section $section, MeetingBlock $meetingBlock)
    {
        $this->ensureSectionInActiveTerm($section);
        abort_if($meetingBlock->section_id !== $section->id, 400, 'Meeting block not in section.');

        $data = $request->validate([
            'type' => ['required','string'],
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        $meetingBlock->update([
            'type' => $data['type'],
            'days_json' => array_values($data['days']),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block updated.');
    }
}
