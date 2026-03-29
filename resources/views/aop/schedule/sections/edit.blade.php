<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Edit Section</x-slot:title>

  @php
    $course = $section->offering->catalogCourse;
    $roomRequired = ($section->modality?->value ?? null) !== 'ONLINE';
    $hasMissingInstructor = !$section->instructor_id;
    $hasMissingMeetings = $section->meetingBlocks->isEmpty();
    $hasMissingRoom = $roomRequired && $section->meetingBlocks->contains(fn ($meetingBlock) => !$meetingBlock->room_id);
    $issueCount = ($hasMissingInstructor ? 1 : 0) + ($hasMissingMeetings ? 1 : 0) + ($hasMissingRoom ? 1 : 0) + count($conflictNotes);
    $oldMeetingBlockId = old('meeting_block_id') ? (int) old('meeting_block_id') : null;
    $isAddingNewBlock = old('meeting_section_id') ? (int) old('meeting_section_id') === $section->id && !$oldMeetingBlockId : false;
  @endphp

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Section</span>
      <h1 class="page-title">{{ $course->code }} {{ $section->section_code }}</h1>
      <p class="page-subtitle">{{ $course->title }}</p>

      <div class="summary-strip">
        <div class="summary-stat">
          <div class="summary-stat-label">Instructor</div>
          <div class="summary-stat-value">{{ $section->instructor?->name ?? 'Unassigned' }}</div>
          <div class="summary-stat-note">{{ $hasMissingInstructor ? 'Assign an instructor before publishing.' : 'Primary assignment is in place.' }}</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Modality</div>
          <div class="summary-stat-value">{{ str_replace('_', ' ', $section->modality?->value ?? 'Unspecified') }}</div>
          <div class="summary-stat-note">{{ $roomRequired ? 'Rooms are required for every meeting block.' : 'Room assignment is optional for this section.' }}</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Meeting Blocks</div>
          <div class="summary-stat-value">{{ $section->meetingBlocks->count() }}</div>
          <div class="summary-stat-note">{{ $hasMissingMeetings ? 'Add at least one block to make this section schedulable.' : 'Current blocks are listed below for fast editing.' }}</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Readiness</div>
          <div class="summary-stat-value">{{ $issueCount === 0 ? 'Ready' : $issueCount.' issue'.($issueCount === 1 ? '' : 's') }}</div>
          <div class="summary-stat-note">{{ $issueCount === 0 ? 'No current blockers or conflicts detected.' : 'Use the notes panel to resolve remaining blockers.' }}</div>
        </div>
      </div>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home', ['focus' => $section->id]) }}#section-{{ $section->id }}">Back to Schedule</a>
        <a class="btn secondary" href="{{ route('aop.schedule.sections.index', ['q' => $course->code]) }}">Section Directory</a>
        <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
        <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Syllabus</a>
      </div>
    </section>

    <section class="editor-layout">
      <div class="editor-main">
        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Section setup</h2>
              <p class="workspace-copy">Section code, instructor, modality, and notes.</p>
            </div>
          </div>

          <form method="POST" action="{{ route('aop.schedule.sections.update', $section) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="section_id" value="{{ $section->id }}">

            <div class="inline-form-grid">
              <div>
                <label for="section_code">Section code</label>
                <input id="section_code" name="section_code" required value="{{ old('section_code', $section->section_code) }}">
              </div>
              <div>
                <label for="instructor_id">Instructor</label>
                <select id="instructor_id" name="instructor_id">
                  <option value="">Unassigned</option>
                  @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" {{ (string) old('instructor_id', $section->instructor_id) === (string) $instructor->id ? 'selected' : '' }}>
                      {{ $instructor->name }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="modality">Modality</label>
                <select id="modality" name="modality" required>
                  @foreach ($modalities as $modality)
                    <option value="{{ $modality->value }}" {{ old('modality', $section->modality?->value) === $modality->value ? 'selected' : '' }}>
                      {{ str_replace('_', ' ', $modality->value) }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="notes">Notes</label>
                <input id="notes" name="notes" value="{{ old('notes', $section->notes) }}" placeholder="Optional planning notes">
              </div>
            </div>

            <div class="section-actions">
              <button class="btn" type="submit">Save Section</button>
            </div>
          </form>
        </section>

        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Current meeting blocks</h2>
              <p class="workspace-copy">Edit saved meeting times and rooms.</p>
            </div>
          </div>

          @if($section->meetingBlocks->isEmpty())
            <div class="status-note">No meeting blocks yet. Add one below to move this section into the schedulable state.</div>
          @else
            <div class="meeting-stack">
              @foreach($section->meetingBlocks as $meetingBlock)
                <div class="meeting-card">
                  <div class="meeting-head">
                    <div>
                      <div class="meeting-title">{{ $meetingBlock->type->value }} · {{ implode(', ', $meetingBlock->days_json ?? []) ?: 'No days selected' }}</div>
                      <div class="meeting-copy">
                        {{ substr($meetingBlock->starts_at, 0, 5) }} - {{ substr($meetingBlock->ends_at, 0, 5) }}
                        · {{ $meetingBlock->room?->name ?? ($roomRequired ? 'Room not assigned' : 'Online / no room needed') }}
                      </div>
                    </div>
                    <div class="section-badges">
                      @if($roomRequired && !$meetingBlock->room_id)
                        <span class="badge warn">Room needed</span>
                      @endif
                    </div>
                  </div>

                  <details class="disclosure" {{ $oldMeetingBlockId === $meetingBlock->id ? 'open' : '' }}>
                    <summary class="disclosure-summary">Edit meeting block</summary>
                    <div class="disclosure-body">
                      <form method="POST" action="{{ route('aop.schedule.meetingBlocks.update', [$section, $meetingBlock]) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="meeting_section_id" value="{{ $section->id }}">
                        <input type="hidden" name="meeting_block_id" value="{{ $meetingBlock->id }}">

                        <div class="inline-form-grid">
                          <div>
                            <label for="block_type_{{ $meetingBlock->id }}">Type</label>
                            <select id="block_type_{{ $meetingBlock->id }}" name="type" required>
                              @foreach ($meetingBlockTypes as $type)
                                <option value="{{ $type->value }}" {{ ((old('meeting_block_id') == $meetingBlock->id ? old('type') : $meetingBlock->type->value) === $type->value) ? 'selected' : '' }}>
                                  {{ $type->value }}
                                </option>
                              @endforeach
                            </select>
                          </div>
                          <div>
                            <label for="block_room_{{ $meetingBlock->id }}">Room</label>
                            <select id="block_room_{{ $meetingBlock->id }}" name="room_id">
                              <option value="">No room</option>
                              @foreach ($rooms as $room)
                                <option value="{{ $room->id }}" {{ (string) (old('meeting_block_id') == $meetingBlock->id ? old('room_id') : $meetingBlock->room_id) === (string) $room->id ? 'selected' : '' }}>
                                  {{ $room->name }}
                                </option>
                              @endforeach
                            </select>
                          </div>
                          <div>
                            <label for="starts_at_{{ $meetingBlock->id }}">Start</label>
                            <input id="starts_at_{{ $meetingBlock->id }}" type="time" name="starts_at" value="{{ old('meeting_block_id') == $meetingBlock->id ? old('starts_at') : substr($meetingBlock->starts_at, 0, 5) }}" required>
                          </div>
                          <div>
                            <label for="ends_at_{{ $meetingBlock->id }}">End</label>
                            <input id="ends_at_{{ $meetingBlock->id }}" type="time" name="ends_at" value="{{ old('meeting_block_id') == $meetingBlock->id ? old('ends_at') : substr($meetingBlock->ends_at, 0, 5) }}" required>
                          </div>
                        </div>

                        <label>Days</label>
                        <div class="inline-form-grid-3">
                          @foreach ($weekDays as $day)
                            <label class="checkbox-row">
                              <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, old('meeting_block_id') == $meetingBlock->id ? (old('days', []) ?: []) : ($meetingBlock->days_json ?? []), true) ? 'checked' : '' }}>
                              <span>{{ $day }}</span>
                            </label>
                          @endforeach
                        </div>

                        <label for="block_notes_{{ $meetingBlock->id }}">Notes</label>
                        <input id="block_notes_{{ $meetingBlock->id }}" name="notes" value="{{ old('meeting_block_id') == $meetingBlock->id ? old('notes') : $meetingBlock->notes }}" placeholder="Optional notes">

                        <div class="section-actions">
                          <button class="btn secondary" type="submit">Save Meeting Block</button>
                        </div>
                      </form>
                    </div>
                  </details>
                </div>
              @endforeach
            </div>
          @endif
        </section>

        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Add a meeting block</h2>
              <p class="workspace-copy">Find an open slot or enter a new block.</p>
            </div>
          </div>

          <div class="surface-note">
            <div class="inline-form-grid">
              <div>
                <label for="suggestDuration">Duration (minutes)</label>
                <input id="suggestDuration" type="number" min="15" step="15" value="60">
              </div>
              <div class="md:col-span-2">
                <label for="suggestDays">Preferred days</label>
                <input id="suggestDays" type="text" placeholder="Mon,Wed,Fri">
              </div>
              <div class="actions md:items-end">
                <button id="suggestSlotButton" type="button" class="btn secondary">Find Open Slots</button>
              </div>
            </div>
            <p class="table-note">Suggestions honor room and instructor availability using the active term buffer rules.</p>
            <div id="suggestionStatus" class="table-note" aria-live="polite"></div>
            <div id="suggestionsList" class="suggestion-list"></div>
          </div>

          <form method="POST" id="addBlockForm" action="{{ route('aop.schedule.meetingBlocks.store', $section) }}" class="mt-5">
            @csrf
            <input type="hidden" name="meeting_section_id" value="{{ $section->id }}">

            <div class="inline-form-grid">
              <div>
                <label for="new_type">Type</label>
                <select id="new_type" name="type" required>
                  @foreach ($meetingBlockTypes as $type)
                    <option value="{{ $type->value }}" {{ $isAddingNewBlock && old('type') === $type->value ? 'selected' : '' }}>{{ $type->value }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="new_room_id">Room</label>
                <select id="new_room_id" name="room_id">
                  <option value="">No room</option>
                  @foreach ($rooms as $room)
                    <option value="{{ $room->id }}" {{ (string) ($isAddingNewBlock ? old('room_id') : null) === (string) $room->id ? 'selected' : '' }}>
                      {{ $room->name }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="new_starts_at">Start</label>
                <input id="new_starts_at" type="time" name="starts_at" value="{{ $isAddingNewBlock ? old('starts_at') : '' }}" required>
              </div>
              <div>
                <label for="new_ends_at">End</label>
                <input id="new_ends_at" type="time" name="ends_at" value="{{ $isAddingNewBlock ? old('ends_at') : '' }}" required>
              </div>
            </div>

            <label>Days</label>
            <div class="inline-form-grid-3">
              @foreach ($weekDays as $day)
                <label class="checkbox-row">
                  <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $isAddingNewBlock ? (old('days', []) ?: []) : [], true) ? 'checked' : '' }}>
                  <span>{{ $day }}</span>
                </label>
              @endforeach
            </div>

            <label for="new_block_notes">Notes</label>
            <input id="new_block_notes" name="notes" value="{{ $isAddingNewBlock ? old('notes') : '' }}" placeholder="Optional notes">

            <div class="section-actions">
              <button class="btn" type="submit">Add Meeting Block</button>
            </div>
          </form>
        </section>
      </div>

      <aside class="editor-side">
        <section class="watchlist">
          <div class="briefing-kicker">Readiness</div>
          <h2 class="watchlist-title">{{ $issueCount === 0 ? 'Section is ready' : 'Section needs attention' }}</h2>
          <p class="watchlist-copy">Resolve the items below and this section is ready to move cleanly through readiness and publishing.</p>

          <div class="watchlist-group">
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Instructor assignment</div>
                <div class="watchlist-note">Required before release</div>
              </div>
              <span class="watchlist-value {{ $hasMissingInstructor ? 'warn' : 'good' }}">{{ $hasMissingInstructor ? 'Missing' : 'Set' }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Meeting pattern</div>
                <div class="watchlist-note">At least one block required</div>
              </div>
              <span class="watchlist-value {{ $hasMissingMeetings ? 'warn' : 'good' }}">{{ $hasMissingMeetings ? 'Missing' : 'Set' }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Room coverage</div>
                <div class="watchlist-note">{{ $roomRequired ? 'Required for this modality' : 'Optional for online delivery' }}</div>
              </div>
              <span class="watchlist-value {{ $hasMissingRoom ? 'warn' : 'good' }}">{{ $hasMissingRoom ? 'Missing' : 'Covered' }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Conflict notes</div>
                <div class="watchlist-note">Class and office-hour overlap checks</div>
              </div>
              <span class="watchlist-value {{ count($conflictNotes) > 0 ? 'warn' : 'good' }}">{{ count($conflictNotes) }}</span>
            </div>
          </div>
        </section>

        <section class="watchlist">
          <div class="briefing-kicker">Course</div>
          <h2 class="watchlist-title">{{ $course->code }} · {{ $course->title }}</h2>
          <p class="watchlist-copy">Key course details that affect section planning.</p>

          <ul class="helper-list">
            <li><strong class="block text-slate-900">Credits</strong>{{ $course->credits_text ?: ($course->credits ? number_format((float) $course->credits, 2).' credits' : '—') }}</li>
            <li><strong class="block text-slate-900">Prerequisite</strong>{{ $section->offering->prereq_override ?: ($course->prereq_text ?: '—') }}</li>
            <li><strong class="block text-slate-900">Corequisite</strong>{{ $section->offering->coreq_override ?: ($course->coreq_text ?: '—') }}</li>
            <li><strong class="block text-slate-900">Offering notes</strong>{{ $section->offering->notes ?: 'No offering-level notes yet.' }}</li>
          </ul>
        </section>

        <section class="watchlist">
          <div class="briefing-kicker">Conflicts</div>
          <h2 class="watchlist-title">Current checks</h2>
          <p class="watchlist-copy">Issues found in the saved meeting pattern.</p>

          @if(count($conflictNotes) === 0)
            <div class="status-note mt-5">No room or instructor conflicts are currently detected for the existing blocks.</div>
          @else
            <ul class="helper-list mt-5">
              @foreach($conflictNotes as $note)
                <li>{{ $note }}</li>
              @endforeach
            </ul>
          @endif
        </section>
      </aside>
    </section>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const button = document.getElementById('suggestSlotButton');
      const durationInput = document.getElementById('suggestDuration');
      const daysInput = document.getElementById('suggestDays');
      const list = document.getElementById('suggestionsList');
      const status = document.getElementById('suggestionStatus');
      const form = document.getElementById('addBlockForm');

      const escapeHtml = (value) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

      const applySuggestion = (payload) => {
        const days = (payload.days || '').split(',').filter(Boolean);

        form.querySelectorAll('input[type="checkbox"][name="days[]"]').forEach((checkbox) => {
          checkbox.checked = days.includes(checkbox.value);
        });

        form.querySelector('input[name="starts_at"]').value = payload.startsAt || '';
        form.querySelector('input[name="ends_at"]').value = payload.endsAt || '';
        form.querySelector('select[name="room_id"]').value = payload.roomId || '';

        status.textContent = 'Suggestion applied below. Review the room and save the meeting block when ready.';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      };

      button?.addEventListener('click', async () => {
        const duration = durationInput.value || 60;
        const days = daysInput.value || '';

        button.disabled = true;
        status.textContent = 'Searching for conflict-aware openings...';
        list.innerHTML = '';

        try {
          const response = await fetch("{{ route('aop.schedule.sections.suggest', $section) }}?duration=" + encodeURIComponent(duration) + "&days=" + encodeURIComponent(days));
          const data = await response.json();
          const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];

          if (suggestions.length === 0) {
            status.textContent = 'No conflicts-free slots found. Try a different duration or a wider day pattern.';
            return;
          }

          status.textContent = suggestions.length + ' suggested slot' + (suggestions.length === 1 ? '' : 's') + ' found.';
          list.innerHTML = suggestions.map((suggestion) => {
            const daysLabel = (suggestion.days || []).join(', ');
            const roomLabel = suggestion.available_rooms.length > 0
              ? suggestion.available_rooms.map((room) => room.name).join(', ')
              : 'No rooms currently free';

            return `
              <div class="suggestion-item">
                <div>
                  <div class="meeting-title">${escapeHtml(daysLabel)} · ${escapeHtml(suggestion.starts_at)} - ${escapeHtml(suggestion.ends_at)}</div>
                  <div class="suggestion-meta">Open rooms: ${escapeHtml(roomLabel)}</div>
                </div>
                <button
                  type="button"
                  class="btn secondary"
                  data-days="${escapeHtml((suggestion.days || []).join(','))}"
                  data-start="${escapeHtml(suggestion.starts_at)}"
                  data-end="${escapeHtml(suggestion.ends_at)}"
                  data-room="${suggestion.available_rooms.length > 0 ? suggestion.available_rooms[0].id : ''}"
                >
                  Use Slot
                </button>
              </div>
            `;
          }).join('');
        } catch (error) {
          status.textContent = 'Unable to load suggestions right now. You can still enter the meeting block manually.';
        } finally {
          button.disabled = false;
        }
      });

      list?.addEventListener('click', (event) => {
        const target = event.target.closest('button[data-days]');
        if (!target) {
          return;
        }

        applySuggestion({
          days: target.dataset.days,
          startsAt: target.dataset.start,
          endsAt: target.dataset.end,
          roomId: target.dataset.room,
        });
      });
    });
  </script>
</x-aop-layout>
