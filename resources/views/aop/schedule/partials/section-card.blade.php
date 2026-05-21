@php
  $isEditingThisSection = false;
  $isAddingBlockHere    = false;
  $hasIssues = !empty($sectionCard['conflict_notes'])
      || count(array_filter($sectionCard['issue_badges'], fn($b) => $b['tone'] !== 'good')) > 0;
@endphp

<article
  id="section-{{ $section->id }}"
  class="section-card {{ ($focusSectionId ?? null) === $section->id ? 'section-card-focus' : '' }}"
>

  {{-- Inline error banner (shown only in HTMX responses with a server error) --}}
  @if (!empty($htmxError))
    <div class="issue-callout mb-3">
      <div class="issue-callout-title">Could not save</div>
      <p class="text-sm text-slate-700 mt-1">{{ $htmxError }}</p>
    </div>
  @endif

  {{-- Section row header --}}
  <div class="section-head">
    <div class="min-w-0">
      <h4 class="section-title">
        {{ $course?->code ?? '' }} · Section {{ $section->section_code }}
      </h4>
      <div class="section-info-row">
        <span class="section-info-chip {{ $section->instructor_id ? '' : 'chip-unset' }}">
          {{ $section->instructor?->name ?? 'Instructor unassigned' }}
        </span>
        <span class="section-info-chip">{{ $section->modality->value }}</span>
        <span class="section-info-chip {{ count($sectionCard['meeting_cards']) === 0 ? 'chip-unset' : '' }}">
          {{ count($sectionCard['meeting_cards']) }} meeting block{{ count($sectionCard['meeting_cards']) === 1 ? '' : 's' }}
        </span>
      </div>
    </div>
    <div class="section-badges">
      @foreach($sectionCard['issue_badges'] as $badge)
        @if($badge['tone'] !== 'good')
          <span class="badge {{ $badge['tone'] === 'danger' ? 'danger' : 'warn' }}">{{ $badge['label'] }}</span>
        @endif
      @endforeach
      @if($hasIssues === false)
        <span class="badge success">Ready</span>
      @endif
    </div>
  </div>

  {{-- Conflict callout --}}
  @if(!empty($sectionCard['conflict_notes']))
    <div class="issue-callout">
      <div class="issue-callout-title">Scheduling conflicts</div>
      <ul class="issue-callout-list">
        @foreach($sectionCard['conflict_notes'] as $note)
          <li>{{ $note }}</li>
        @endforeach
      </ul>
      <div class="issue-callout-actions">
        <a class="btn secondary sm" href="{{ route('aop.schedule.calendar.index') }}">Fix in Calendar</a>
        <a class="btn secondary sm" href="{{ route('aop.schedule.readiness.index') }}">Review Readiness</a>
      </div>
    </div>
  @endif

  {{-- Meeting block chips --}}
  @if(count($sectionCard['meeting_cards']) > 0)
    <div class="meeting-chip-stack">
      @foreach($sectionCard['meeting_cards'] as $meetingCard)
        @php
          $mb       = $meetingCard['model'];
          $chipCls  = $meetingCard['room_conflict_count'] > 0 || $meetingCard['instructor_conflict_count'] > 0
              ? 'chip-conflict'
              : ($meetingCard['missing_room'] ? 'chip-missing-room' : '');
          $dayChips = implode(' ', array_map(fn($d) => $dayAbbr[$d] ?? $d, $mb->days_json ?? []));
        @endphp
        <span class="meeting-chip {{ $chipCls }}" title="{{ $mb->type->value }} · {{ $dayChips }} · {{ substr($mb->starts_at,0,5) }}–{{ substr($mb->ends_at,0,5) }}{{ $mb->room ? ' · '.$mb->room->name : '' }}">
          <span class="meeting-chip-type">{{ $mb->type->value }}</span>
          <span class="meeting-chip-sep">·</span>
          <span class="meeting-chip-days">{{ $dayChips ?: '—' }}</span>
          <span class="meeting-chip-sep">·</span>
          <span class="meeting-chip-time">{{ substr($mb->starts_at,0,5) }}–{{ substr($mb->ends_at,0,5) }}</span>
          @if($mb->room)
            <span class="meeting-chip-room">{{ $mb->room->name }}</span>
          @elseif($meetingCard['missing_room'])
            <span class="meeting-chip-room">Room needed</span>
          @endif
        </span>
      @endforeach
    </div>
  @else
    <div class="status-note mt-3">No meeting blocks yet — add one below to make this section schedulable.</div>
  @endif

  {{-- Block edit rows --}}
  <div class="block-edit-rows">

    {{-- Per-block edit rows --}}
    @foreach($sectionCard['meeting_cards'] as $meetingCard)
      @php $meetingBlock = $meetingCard['model']; @endphp
      <details class="block-edit-row"
        x-data="conflictChecker({{ $section->id }}, {{ $section->instructor_id ?? 'null' }})"
        {{ ($oldMeetingBlockId ?? null) === $meetingBlock->id ? 'open' : '' }}>
        <summary class="disclosure-summary">
          Edit {{ $meetingBlock->type->value }}
          · {{ implode(' ', array_map(fn($d) => $dayAbbr[$d] ?? $d, $meetingBlock->days_json ?? [])) ?: 'No days' }}
          · {{ substr($meetingBlock->starts_at,0,5) }}–{{ substr($meetingBlock->ends_at,0,5) }}
          @if($meetingBlock->room) · {{ $meetingBlock->room->name }}@endif
        </summary>
        <div class="disclosure-body">
          <form method="POST"
            action="{{ route('aop.schedule.meetingBlocks.update', [$section, $meetingBlock]) }}"
            hx-post="{{ route('aop.schedule.meetingBlocks.update', [$section, $meetingBlock]) }}"
            hx-target="#section-{{ $section->id }}"
            hx-swap="outerHTML"
            hx-indicator="#section-{{ $section->id }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="from_schedule_home" value="1">
            <input type="hidden" name="meeting_section_id" value="{{ $section->id }}">
            <input type="hidden" name="meeting_block_id" value="{{ $meetingBlock->id }}">

            <div class="inline-form-grid">
              <div>
                <label for="block_type_{{ $meetingBlock->id }}">Type</label>
                <select id="block_type_{{ $meetingBlock->id }}" name="type" required>
                  @foreach($meetingBlockTypes as $type)
                    <option value="{{ $type->value }}" {{ $meetingBlock->type->value === $type->value ? 'selected' : '' }}>{{ $type->value }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="block_room_{{ $meetingBlock->id }}">Room</label>
                <select id="block_room_{{ $meetingBlock->id }}" name="room_id"
                  x-model="roomId" @change="check()">
                  <option value="">No room</option>
                  @foreach($rooms as $room)
                    <option value="{{ $room->id }}" {{ (string) $meetingBlock->room_id === (string) $room->id ? 'selected' : '' }}>{{ $room->name }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="block_start_{{ $meetingBlock->id }}">Start</label>
                <input id="block_start_{{ $meetingBlock->id }}" type="time" name="starts_at"
                  value="{{ substr($meetingBlock->starts_at, 0, 5) }}"
                  x-model="startsAt" @change="check()" required>
              </div>
              <div>
                <label for="block_end_{{ $meetingBlock->id }}">End</label>
                <input id="block_end_{{ $meetingBlock->id }}" type="time" name="ends_at"
                  value="{{ substr($meetingBlock->ends_at, 0, 5) }}"
                  x-model="endsAt" @change="check()" required>
              </div>
            </div>

            <label>Days</label>
            <div class="inline-form-grid-3">
              @foreach($weekDays as $day)
                <label class="checkbox-row">
                  <input type="checkbox" name="days[]" value="{{ $day }}"
                    {{ in_array($day, $meetingBlock->days_json ?? [], true) ? 'checked' : '' }}
                    @change="days = [...$el.closest('form').querySelectorAll('[name=\'days[]\']:checked')].map(c=>c.value); check()">
                  <span>{{ $day }}</span>
                </label>
              @endforeach
            </div>

            <label for="block_notes_{{ $meetingBlock->id }}">Notes</label>
            <input id="block_notes_{{ $meetingBlock->id }}" name="notes" value="{{ $meetingBlock->notes }}" placeholder="Optional notes">

            <div x-show="hasConflict" x-cloak class="conflict-preview-alert">
              <template x-for="c in conflicts" :key="c">
                <div class="conflict-preview-item" x-text="c"></div>
              </template>
              <p class="conflict-preview-note">Preview only — server validates on save.</p>
            </div>

            <div class="section-actions">
              <button class="btn secondary" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save meeting block</button>
            </div>
          </form>
        </div>
      </details>
    @endforeach

    {{-- Add meeting block row --}}
    <details class="block-edit-row"
      x-data="conflictChecker({{ $section->id }}, {{ $section->instructor_id ?? 'null' }})"
      {{ $sectionCard['has_missing_meetings'] ? 'open' : '' }}>
      <summary class="disclosure-summary">+ Add meeting block</summary>
      <div class="disclosure-body">
        <form method="POST"
          action="{{ route('aop.schedule.meetingBlocks.store', $section) }}"
          hx-post="{{ route('aop.schedule.meetingBlocks.store', $section) }}"
          hx-target="#section-{{ $section->id }}"
          hx-swap="outerHTML"
          hx-indicator="#section-{{ $section->id }}">
          @csrf
          <input type="hidden" name="from_schedule_home" value="1">
          <input type="hidden" name="meeting_section_id" value="{{ $section->id }}">

          <div class="inline-form-grid">
            <div>
              <label for="new_block_type_{{ $section->id }}">Type</label>
              <select id="new_block_type_{{ $section->id }}" name="type" required>
                @foreach($meetingBlockTypes as $type)
                  <option value="{{ $type->value }}">{{ $type->value }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="new_block_room_{{ $section->id }}">Room</label>
              <select id="new_block_room_{{ $section->id }}" name="room_id"
                x-model="roomId" @change="check()">
                <option value="">No room</option>
                @foreach($rooms as $room)
                  <option value="{{ $room->id }}">{{ $room->name }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="new_block_start_{{ $section->id }}">Start</label>
              <input id="new_block_start_{{ $section->id }}" type="time" name="starts_at"
                x-model="startsAt" @change="check()" required>
            </div>
            <div>
              <label for="new_block_end_{{ $section->id }}">End</label>
              <input id="new_block_end_{{ $section->id }}" type="time" name="ends_at"
                x-model="endsAt" @change="check()" required>
            </div>
          </div>

          <label>Days</label>
          <div class="inline-form-grid-3">
            @foreach($weekDays as $day)
              <label class="checkbox-row">
                <input type="checkbox" name="days[]" value="{{ $day }}"
                  @change="days = [...$el.closest('form').querySelectorAll('[name=\'days[]\']:checked')].map(c=>c.value); check()">
                <span>{{ $day }}</span>
              </label>
            @endforeach
          </div>

          <label for="new_block_notes_{{ $section->id }}">Notes</label>
          <input id="new_block_notes_{{ $section->id }}" name="notes" placeholder="Optional notes">

          <div x-show="hasConflict" x-cloak class="conflict-preview-alert">
            <template x-for="c in conflicts" :key="c">
              <div class="conflict-preview-item" x-text="c"></div>
            </template>
            <p class="conflict-preview-note">Preview only — server validates on save.</p>
          </div>

          <div class="section-actions">
            <button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Add meeting block</button>
          </div>
        </form>
      </div>
    </details>

    {{-- Edit section row --}}
    <details class="block-edit-row" {{ ($focusSectionId ?? null) === $section->id ? 'open' : '' }}>
      <summary class="disclosure-summary">Edit section details</summary>
      <div class="disclosure-body">
        <form method="POST" action="{{ route('aop.schedule.sections.update', $section) }}">
          @csrf
          @method('PUT')
          <input type="hidden" name="from_schedule_home" value="1">
          <input type="hidden" name="section_id" value="{{ $section->id }}">

          <div class="inline-form-grid">
            <div>
              <label for="section_code_edit_{{ $section->id }}">Section code</label>
              <input id="section_code_edit_{{ $section->id }}" name="section_code" value="{{ $section->section_code }}" required>
            </div>
            <div>
              <label for="section_instructor_{{ $section->id }}">Instructor</label>
              <select id="section_instructor_{{ $section->id }}" name="instructor_id">
                <option value="">Unassigned</option>
                @foreach($instructors as $instructor)
                  <option value="{{ $instructor->id }}" {{ (string) $section->instructor_id === (string) $instructor->id ? 'selected' : '' }}>
                    {{ $instructor->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="section_modality_{{ $section->id }}">Modality</label>
              <select id="section_modality_{{ $section->id }}" name="modality" required>
                @foreach($modalities as $modality)
                  <option value="{{ $modality->value }}" {{ $section->modality->value === $modality->value ? 'selected' : '' }}>
                    {{ $modality->value }}
                  </option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="section_notes_edit_{{ $section->id }}">Notes</label>
              <input id="section_notes_edit_{{ $section->id }}" name="notes" value="{{ $section->notes }}" placeholder="Optional notes">
            </div>
          </div>

          <div class="section-actions">
            <button class="btn secondary" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save section</button>
          </div>
        </form>
      </div>
    </details>

  </div>{{-- end block-edit-rows --}}

  {{-- Section quick-link toolbar --}}
  <div class="section-toolbar">
    <a class="btn secondary sm" href="{{ route('aop.schedule.sections.edit', $section) }}">Full editor</a>
    @if($section->instructor_id)
      <a class="btn secondary sm" href="{{ route('aop.schedule.officeHours.show', $section->instructor_id) }}">Office hours</a>
    @endif
    @if(!$scheduleLocked)
      <form method="POST" action="{{ route('aop.schedule.sections.destroy', $section) }}"
            style="display:inline;"
            onsubmit="return confirm('Remove {{ addslashes(($section->offering->catalogCourse->code ?? '').' '.$section->section_code) }} and all its meeting blocks? This cannot be undone.')">
        @csrf
        @method('DELETE')
        <input type="hidden" name="from_schedule_home" value="1">
        <button type="submit" class="btn link-danger sm">Remove section</button>
      </form>
    @endif
  </div>

</article>
