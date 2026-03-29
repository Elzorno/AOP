<x-aop-layout :activeTermLabel="($term ? 'Active Term: '.$term->code.' - '.$term->name : 'No active term selected')">
  <x-slot:title>Schedule Studio</x-slot:title>

  @php
    $blockingIssues = $summary
        ? $summary['sections_missing_instructor_count']
            + $summary['sections_missing_meeting_blocks_count']
            + $summary['meeting_blocks_missing_room_count']
            + $summary['room_conflict_count']
            + $summary['instructor_conflict_count']
        : 0;
    $officeHourIssues = $summary['office_hours_failing_count'] ?? 0;
    $isPublishReady = $summary && $blockingIssues === 0 && $officeHourIssues === 0;
    $scheduleLocked = (bool) ($summary['schedule_locked'] ?? false);
    $oldOfferingId = old('offering_id') ? (int) old('offering_id') : null;
    $oldSectionId = old('section_id') ? (int) old('section_id') : null;
    $oldMeetingSectionId = old('meeting_section_id') ? (int) old('meeting_section_id') : null;
    $oldMeetingBlockId = old('meeting_block_id') ? (int) old('meeting_block_id') : null;
    $hasOfferingDraft = collect([
        old('catalog_course_id'),
        old('delivery_method'),
        old('prereq_override'),
        old('coreq_override'),
    ])->filter(fn ($value) => filled($value))->isNotEmpty();
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Schedule studio</div>
        <h1 class="briefing-title">{{ $term ? $term->code.' scheduling workspace' : 'Choose an active term to start scheduling.' }}</h1>
        <p class="briefing-copy">
          {{ $term
              ? 'Create offerings, add sections, assign instructors, manage meetings, and review readiness without leaving the schedule workspace.'
              : 'Select an active term first, then return here to build the schedule.' }}
        </p>

        @if ($term)
          <div class="status-ribbon">
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot bg-blue-500"></span>
              {{ $summary['offerings_count'] }} offerings
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot bg-slate-500"></span>
              {{ $summary['sections_count'] }} sections
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $blockingIssues === 0 ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
              {{ $blockingIssues }} blocking issues
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $scheduleLocked ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
              {{ $scheduleLocked ? 'Schedule locked' : 'Schedule editable' }}
            </span>
          </div>

          <div class="mt-8 quick-actions">
            <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
            <a class="btn secondary" href="{{ route('aop.schedule.calendar.index') }}">Calendar</a>
            <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Grids</a>
            <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
          </div>
        @else
          <div class="mt-8 quick-actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Open Terms</a>
          </div>
        @endif
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Readiness</div>
        <h2 class="watchlist-title">{{ $term ? ($isPublishReady ? 'Ready for release' : 'Needs attention') : 'No active term' }}</h2>
        <p class="watchlist-copy">
          {{ $term
              ? 'Current schedule state, release status, and latest publication.'
              : 'Scheduling tools will appear here after a term is activated.' }}
        </p>

        <div class="watchlist-group">
          @if ($term)
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Latest publication</div>
                <div class="watchlist-note">Most recent released snapshot</div>
              </div>
              <span class="watchlist-value {{ $summary['latest_publication_version'] ? 'good' : 'warn' }}">{{ $summary['latest_publication_version'] ? 'v'.$summary['latest_publication_version'] : 'None' }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Term status</div>
                <div class="watchlist-note">Current lifecycle state</div>
              </div>
              <span class="watchlist-value {{ $summary['term_status'] === 'published' ? 'good' : 'warn' }}">{{ str_replace('_', ' ', ucfirst($summary['term_status'])) }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Schedule state</div>
                <div class="watchlist-note">Editing availability</div>
              </div>
              <span class="watchlist-value {{ $scheduleLocked ? 'warn' : 'good' }}">{{ $scheduleLocked ? 'Locked' : 'Open' }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Office-hours issues</div>
                <div class="watchlist-note">Full-time instructor coverage</div>
              </div>
              <span class="watchlist-value {{ $officeHourIssues === 0 ? 'good' : 'warn' }}">{{ $officeHourIssues }}</span>
            </div>
          @else
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Current blocker</div>
                <div class="watchlist-note">Scheduling starts with one active term</div>
              </div>
              <span class="watchlist-value warn">Unset</span>
            </div>
          @endif
        </div>
      </aside>
    </section>

    @if (!$term)
      <section class="workspace-card">
        <div class="workspace-header">
          <div>
            <h2 class="workspace-title">Active term required</h2>
            <p class="workspace-copy">Choose the active term, then return here to manage offerings, sections, and meeting blocks.</p>
          </div>
          <div class="actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Manage Terms</a>
          </div>
        </div>
      </section>
    @else
      <section class="studio-grid">
        <div class="studio-main">
          <section class="workspace-card">
            <div class="workspace-header">
              <div>
                <div class="briefing-kicker">Fast lane</div>
                <h2 class="workspace-title">Add offerings and jump straight into sections.</h2>
                <p class="workspace-copy">New offerings start here. Section creation stays inside each offering so course context never gets lost.</p>
              </div>
            </div>

            @if ($scheduleLocked)
              <div class="status-note mt-5">The active term is locked. Unlock the schedule before creating or updating offerings, sections, or meeting blocks.</div>
            @endif

            <div class="stack-grid-2 mt-5">
              <form method="POST" action="{{ route('aop.schedule.offerings.store') }}" class="surface-note">
                @csrf
                <input type="hidden" name="from_schedule_home" value="1">

                <label for="catalog_course_id">Catalog course</label>
                <select id="catalog_course_id" name="catalog_course_id" required>
                  <option value="" disabled {{ old('catalog_course_id') ? '' : 'selected' }}>
                    {{ $availableCourses->isEmpty() ? 'All active courses are already offered' : 'Choose a course' }}
                  </option>
                  @foreach($availableCourses as $course)
                    <option value="{{ $course->id }}" {{ (string) old('catalog_course_id') === (string) $course->id ? 'selected' : '' }}>
                      {{ $course->code }} - {{ $course->title }}
                    </option>
                  @endforeach
                </select>

                <div class="inline-form-grid-2">
                  <div>
                    <label for="delivery_method">Delivery method</label>
                    <input id="delivery_method" name="delivery_method" value="{{ old('delivery_method') }}" placeholder="Lecture, Lab, Hybrid">
                  </div>
                  <div>
                    <label for="offering_notes">Notes</label>
                    <input id="offering_notes" name="notes" value="{{ old('notes') }}" placeholder="Optional planning notes">
                  </div>
                </div>

                <details class="disclosure" {{ $hasOfferingDraft ? 'open' : '' }}>
                  <summary class="disclosure-summary">Advanced offering details</summary>
                  <div class="disclosure-body">
                    <div class="inline-form-grid-2">
                      <div>
                        <label for="prereq_override">Prerequisite override</label>
                        <textarea id="prereq_override" name="prereq_override" rows="3">{{ old('prereq_override') }}</textarea>
                      </div>
                      <div>
                        <label for="coreq_override">Corequisite override</label>
                        <textarea id="coreq_override" name="coreq_override" rows="3">{{ old('coreq_override') }}</textarea>
                      </div>
                    </div>
                  </div>
                </details>

                <div class="section-actions">
                  <button class="btn" type="submit" {{ $scheduleLocked || $availableCourses->isEmpty() ? 'disabled' : '' }}>Add Offering</button>
                </div>
              </form>

              <div class="surface-note">
                <strong class="text-slate-900">Workflow</strong>
                <div class="mt-3 text-sm leading-6 text-slate-600">
                  1. Add an offering for the active term.
                  <br>
                  2. Add sections directly inside that offering card.
                  <br>
                  3. Add meeting blocks and resolve conflicts inline.
                </div>
                @if($availableCourses->isEmpty())
                  <div class="status-note mt-4">Every active catalog course already has an offering in this term. Add more sections below or activate another course in Catalog.</div>
                @endif
              </div>
            </div>
          </section>

          <section class="workspace-card">
            <div class="workspace-header">
              <div>
                <div class="briefing-kicker">Schedule studio</div>
                <h2 class="workspace-title">Offerings, sections, and meetings in one place.</h2>
                <p class="workspace-copy">Filter the workspace, update section assignments, and manage meeting blocks without leaving the current term context.</p>
              </div>
            </div>

            <form method="GET" action="{{ route('aop.schedule.home') }}">
              <div class="filter-grid">
                <div class="md:col-span-2 xl:col-span-2">
                  <label for="q">Search</label>
                  <input id="q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Course, section, instructor">
                </div>
                <div>
                  <label for="issue">View</label>
                  <select id="issue" name="issue">
                    <option value="all" {{ $filters['issue'] === 'all' ? 'selected' : '' }}>All sections</option>
                    <option value="attention" {{ $filters['issue'] === 'attention' ? 'selected' : '' }}>Needs attention</option>
                    <option value="missing_instructor" {{ $filters['issue'] === 'missing_instructor' ? 'selected' : '' }}>Missing instructor</option>
                    <option value="missing_meetings" {{ $filters['issue'] === 'missing_meetings' ? 'selected' : '' }}>Missing meetings</option>
                    <option value="missing_room" {{ $filters['issue'] === 'missing_room' ? 'selected' : '' }}>Missing room</option>
                    <option value="conflicts" {{ $filters['issue'] === 'conflicts' ? 'selected' : '' }}>Conflicts only</option>
                    <option value="ready" {{ $filters['issue'] === 'ready' ? 'selected' : '' }}>Ready</option>
                  </select>
                </div>
                <div class="actions md:items-end">
                  <button class="btn secondary" type="submit">Apply</button>
                  <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Reset</a>
                </div>
              </div>
            </form>

            <div class="offering-stack">
              @forelse($offerings as $offeringCard)
                @php
                  $offering = $offeringCard['model'];
                  $course = $offeringCard['course'];
                @endphp
                <article id="offering-{{ $offering->id }}" class="offering-card {{ $offeringCard['focus'] ? 'offering-card-focus' : '' }}">
                  <div class="offering-head">
                    <div>
                      <div class="offering-kicker">Offering</div>
                      <h3 class="offering-title">{{ $course?->code ?? 'Course' }} - {{ $course?->title ?? 'Untitled course' }}</h3>
                      <p class="offering-copy">{{ $offering->delivery_method ?: 'Delivery method not set' }}</p>
                    </div>
                    <div class="section-badges">
                      <span class="badge info">{{ $offeringCard['all_sections_count'] }} section{{ $offeringCard['all_sections_count'] === 1 ? '' : 's' }}</span>
                      @if($offeringCard['issue_count'] > 0)
                        <span class="badge danger">{{ $offeringCard['issue_count'] }} issue{{ $offeringCard['issue_count'] === 1 ? '' : 's' }}</span>
                      @else
                        <span class="badge success">Ready</span>
                      @endif
                      @if($offeringCard['has_empty_offering'])
                        <span class="badge warn">No sections yet</span>
                      @endif
                    </div>
                  </div>

                  <div class="offering-meta">
                    <div class="offering-meta-card">
                      <strong class="block text-slate-900">Credits</strong>
                      <span>{{ $course?->credits_text ?: ($course?->credits ? number_format((float) $course->credits, 2).' credits' : '—') }}</span>
                    </div>
                    <div class="offering-meta-card">
                      <strong class="block text-slate-900">Prerequisite</strong>
                      <span>{{ $offering->prereq_override ?: ($course?->prereq_text ?: '—') }}</span>
                    </div>
                    <div class="offering-meta-card">
                      <strong class="block text-slate-900">Corequisite</strong>
                      <span>{{ $offering->coreq_override ?: ($course?->coreq_text ?: '—') }}</span>
                    </div>
                  </div>

                  <details class="disclosure" {{ $offeringCard['focus'] || $offeringCard['has_empty_offering'] || $oldOfferingId === $offering->id ? 'open' : '' }}>
                    <summary class="disclosure-summary">Add section to this offering</summary>
                    <div class="disclosure-body">
                      <form method="POST" action="{{ route('aop.schedule.sections.store') }}">
                        @csrf
                        <input type="hidden" name="from_schedule_home" value="1">
                        <input type="hidden" name="offering_id" value="{{ $offering->id }}">

                        <div class="inline-form-grid">
                          <div>
                            <label for="section_code_{{ $offering->id }}">Section code</label>
                            <input id="section_code_{{ $offering->id }}" name="section_code" value="{{ old('offering_id') == $offering->id ? old('section_code') : '' }}" placeholder="01" required>
                          </div>
                          <div>
                            <label for="instructor_id_{{ $offering->id }}">Instructor</label>
                            <select id="instructor_id_{{ $offering->id }}" name="instructor_id">
                              <option value="">Unassigned</option>
                              @foreach($instructors as $instructor)
                                <option value="{{ $instructor->id }}" {{ old('offering_id') == $offering->id && (string) old('instructor_id') === (string) $instructor->id ? 'selected' : '' }}>
                                  {{ $instructor->name }}
                                </option>
                              @endforeach
                            </select>
                          </div>
                          <div>
                            <label for="modality_{{ $offering->id }}">Modality</label>
                            <select id="modality_{{ $offering->id }}" name="modality" required>
                              @foreach($modalities as $modality)
                                <option value="{{ $modality->value }}" {{ old('offering_id') == $offering->id && old('modality') === $modality->value ? 'selected' : '' }}>
                                  {{ $modality->value }}
                                </option>
                              @endforeach
                            </select>
                          </div>
                          <div>
                            <label for="section_notes_{{ $offering->id }}">Notes</label>
                            <input id="section_notes_{{ $offering->id }}" name="notes" value="{{ old('offering_id') == $offering->id ? old('notes') : '' }}" placeholder="Optional notes">
                          </div>
                        </div>

                        <div class="section-actions">
                          <button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Add Section</button>
                        </div>
                      </form>
                    </div>
                  </details>

                  @if($offeringCard['sections'] === [])
                    <div class="status-note mt-4">No sections match the current filter for this offering.</div>
                  @endif

                  <div class="section-stack">
                    @foreach($offeringCard['sections'] as $sectionCard)
                      @php
                        $section = $sectionCard['model'];
                        $isEditingThisSection = $oldSectionId === $section->id;
                        $isAddingBlockToThisSection = $oldMeetingSectionId === $section->id && !$oldMeetingBlockId;
                      @endphp
                      <article id="section-{{ $section->id }}" class="section-card {{ $focusSectionId === $section->id ? 'section-card-focus' : '' }}">
                        <div class="section-head">
                          <div>
                            <h4 class="section-title">Section {{ $section->section_code }}</h4>
                            <p class="section-copy">
                              {{ $section->instructor?->name ?? 'Instructor not assigned' }}
                              · {{ $section->modality->value }}
                              · {{ count($sectionCard['meeting_cards']) }} meeting block{{ count($sectionCard['meeting_cards']) === 1 ? '' : 's' }}
                            </p>
                          </div>
                          <div class="section-badges">
                            @foreach($sectionCard['issue_badges'] as $badge)
                              <span class="badge {{ $badge['tone'] === 'good' ? 'success' : ($badge['tone'] === 'danger' ? 'danger' : 'warn') }}">{{ $badge['label'] }}</span>
                            @endforeach
                          </div>
                        </div>

                        @if($sectionCard['conflict_notes'] !== [])
                          <div class="status-note mt-4">
                            <strong class="block text-slate-900">Conflicts</strong>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                              @foreach($sectionCard['conflict_notes'] as $note)
                                <li>{{ $note }}</li>
                              @endforeach
                            </ul>
                          </div>
                        @endif

                        <details class="disclosure" {{ $focusSectionId === $section->id || $isEditingThisSection ? 'open' : '' }}>
                          <summary class="disclosure-summary">Edit section</summary>
                          <div class="disclosure-body">
                            <form method="POST" action="{{ route('aop.schedule.sections.update', $section) }}">
                              @csrf
                              @method('PUT')
                              <input type="hidden" name="from_schedule_home" value="1">
                              <input type="hidden" name="section_id" value="{{ $section->id }}">

                              <div class="inline-form-grid">
                                <div>
                                  <label for="section_code_edit_{{ $section->id }}">Section code</label>
                                  <input id="section_code_edit_{{ $section->id }}" name="section_code" value="{{ $isEditingThisSection ? old('section_code') : $section->section_code }}" required>
                                </div>
                                <div>
                                  <label for="section_instructor_{{ $section->id }}">Instructor</label>
                                  <select id="section_instructor_{{ $section->id }}" name="instructor_id">
                                    <option value="">Unassigned</option>
                                    @foreach($instructors as $instructor)
                                      <option value="{{ $instructor->id }}" {{ (string) ($isEditingThisSection ? old('instructor_id') : $section->instructor_id) === (string) $instructor->id ? 'selected' : '' }}>
                                        {{ $instructor->name }}
                                      </option>
                                    @endforeach
                                  </select>
                                </div>
                                <div>
                                  <label for="section_modality_{{ $section->id }}">Modality</label>
                                  <select id="section_modality_{{ $section->id }}" name="modality" required>
                                    @foreach($modalities as $modality)
                                      <option value="{{ $modality->value }}" {{ ($isEditingThisSection ? old('modality') : $section->modality->value) === $modality->value ? 'selected' : '' }}>
                                        {{ $modality->value }}
                                      </option>
                                    @endforeach
                                  </select>
                                </div>
                                <div>
                                  <label for="section_notes_edit_{{ $section->id }}">Notes</label>
                                  <input id="section_notes_edit_{{ $section->id }}" name="notes" value="{{ $isEditingThisSection ? old('notes') : $section->notes }}" placeholder="Optional notes">
                                </div>
                              </div>

                              <div class="section-actions">
                                <button class="btn secondary" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save Section</button>
                                <a class="btn secondary" href="{{ route('aop.schedule.sections.edit', $section) }}">Full Editor</a>
                                <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Syllabus</a>
                                @if($section->instructor_id)
                                  <a class="btn secondary" href="{{ route('aop.schedule.officeHours.show', $section->instructor_id) }}">Office Hours</a>
                                @endif
                              </div>
                            </form>
                          </div>
                        </details>

                        <div class="meeting-stack">
                          @forelse($sectionCard['meeting_cards'] as $meetingCard)
                            @php $meetingBlock = $meetingCard['model']; @endphp
                            <div class="meeting-card">
                              <div class="meeting-head">
                                <div>
                                  <div class="meeting-title">{{ $meetingBlock->type->value }} · {{ implode(', ', $meetingBlock->days_json ?? []) ?: 'No days selected' }}</div>
                                  <div class="meeting-copy">
                                    {{ substr($meetingBlock->starts_at, 0, 5) }} - {{ substr($meetingBlock->ends_at, 0, 5) }}
                                    · {{ $meetingBlock->room?->name ?? ($section->modality->value === 'ONLINE' ? 'Online' : 'Room not assigned') }}
                                  </div>
                                </div>
                                <div class="section-badges">
                                  @if($meetingCard['missing_room'])
                                    <span class="badge warn">Room needed</span>
                                  @endif
                                  @if($meetingCard['room_conflict_count'] > 0)
                                    <span class="badge danger">{{ $meetingCard['room_conflict_count'] }} room conflict{{ $meetingCard['room_conflict_count'] === 1 ? '' : 's' }}</span>
                                  @endif
                                  @if($meetingCard['instructor_conflict_count'] > 0)
                                    <span class="badge danger">{{ $meetingCard['instructor_conflict_count'] }} instructor conflict{{ $meetingCard['instructor_conflict_count'] === 1 ? '' : 's' }}</span>
                                  @endif
                                </div>
                              </div>

                              <details class="disclosure" {{ $oldMeetingBlockId === $meetingBlock->id ? 'open' : '' }}>
                                <summary class="disclosure-summary">Edit meeting block</summary>
                                <div class="disclosure-body">
                                  <form method="POST" action="{{ route('aop.schedule.meetingBlocks.update', [$section, $meetingBlock]) }}">
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
                                            <option value="{{ $type->value }}" {{ ($oldMeetingBlockId === $meetingBlock->id ? old('type') : $meetingBlock->type->value) === $type->value ? 'selected' : '' }}>{{ $type->value }}</option>
                                          @endforeach
                                        </select>
                                      </div>
                                      <div>
                                        <label for="block_room_{{ $meetingBlock->id }}">Room</label>
                                        <select id="block_room_{{ $meetingBlock->id }}" name="room_id">
                                          <option value="">No room</option>
                                          @foreach($rooms as $room)
                                            <option value="{{ $room->id }}" {{ (string) ($oldMeetingBlockId === $meetingBlock->id ? old('room_id') : $meetingBlock->room_id) === (string) $room->id ? 'selected' : '' }}>
                                              {{ $room->name }}
                                            </option>
                                          @endforeach
                                        </select>
                                      </div>
                                      <div>
                                        <label for="block_start_{{ $meetingBlock->id }}">Start</label>
                                        <input id="block_start_{{ $meetingBlock->id }}" type="time" name="starts_at" value="{{ $oldMeetingBlockId === $meetingBlock->id ? old('starts_at') : substr($meetingBlock->starts_at, 0, 5) }}" required>
                                      </div>
                                      <div>
                                        <label for="block_end_{{ $meetingBlock->id }}">End</label>
                                        <input id="block_end_{{ $meetingBlock->id }}" type="time" name="ends_at" value="{{ $oldMeetingBlockId === $meetingBlock->id ? old('ends_at') : substr($meetingBlock->ends_at, 0, 5) }}" required>
                                      </div>
                                    </div>

                                    <label>Days</label>
                                    <div class="inline-form-grid-3">
                                      @foreach($weekDays as $day)
                                        <label class="checkbox-row">
                                          <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $oldMeetingBlockId === $meetingBlock->id ? (old('days', []) ?: []) : ($meetingBlock->days_json ?? []), true) ? 'checked' : '' }}>
                                          <span>{{ $day }}</span>
                                        </label>
                                      @endforeach
                                    </div>

                                    <label for="block_notes_{{ $meetingBlock->id }}">Notes</label>
                                    <input id="block_notes_{{ $meetingBlock->id }}" name="notes" value="{{ $oldMeetingBlockId === $meetingBlock->id ? old('notes') : $meetingBlock->notes }}" placeholder="Optional notes">

                                    <div class="section-actions">
                                      <button class="btn secondary" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save Meeting Block</button>
                                    </div>
                                  </form>
                                </div>
                              </details>
                            </div>
                          @empty
                            <div class="status-note">No meeting blocks yet. Add one below to make this section schedulable.</div>
                          @endforelse
                        </div>

                        <details class="disclosure" {{ $focusSectionId === $section->id || $sectionCard['has_missing_meetings'] || $isAddingBlockToThisSection ? 'open' : '' }}>
                          <summary class="disclosure-summary">Add meeting block</summary>
                          <div class="disclosure-body">
                            <form method="POST" action="{{ route('aop.schedule.meetingBlocks.store', $section) }}">
                              @csrf
                              <input type="hidden" name="from_schedule_home" value="1">
                              <input type="hidden" name="meeting_section_id" value="{{ $section->id }}">

                              <div class="inline-form-grid">
                                <div>
                                  <label for="new_block_type_{{ $section->id }}">Type</label>
                                  <select id="new_block_type_{{ $section->id }}" name="type" required>
                                    @foreach($meetingBlockTypes as $type)
                                      <option value="{{ $type->value }}" {{ ($isAddingBlockToThisSection ? old('type') : null) === $type->value ? 'selected' : '' }}>{{ $type->value }}</option>
                                    @endforeach
                                  </select>
                                </div>
                                <div>
                                  <label for="new_block_room_{{ $section->id }}">Room</label>
                                  <select id="new_block_room_{{ $section->id }}" name="room_id">
                                    <option value="">No room</option>
                                    @foreach($rooms as $room)
                                      <option value="{{ $room->id }}" {{ (string) ($isAddingBlockToThisSection ? old('room_id') : null) === (string) $room->id ? 'selected' : '' }}>{{ $room->name }}</option>
                                    @endforeach
                                  </select>
                                </div>
                                <div>
                                  <label for="new_block_start_{{ $section->id }}">Start</label>
                                  <input id="new_block_start_{{ $section->id }}" type="time" name="starts_at" value="{{ $isAddingBlockToThisSection ? old('starts_at') : '' }}" required>
                                </div>
                                <div>
                                  <label for="new_block_end_{{ $section->id }}">End</label>
                                  <input id="new_block_end_{{ $section->id }}" type="time" name="ends_at" value="{{ $isAddingBlockToThisSection ? old('ends_at') : '' }}" required>
                                </div>
                              </div>

                              <label>Days</label>
                              <div class="inline-form-grid-3">
                                @foreach($weekDays as $day)
                                  <label class="checkbox-row">
                                    <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $isAddingBlockToThisSection ? (old('days', []) ?: []) : [], true) ? 'checked' : '' }}>
                                    <span>{{ $day }}</span>
                                  </label>
                                @endforeach
                              </div>

                              <label for="new_block_notes_{{ $section->id }}">Notes</label>
                              <input id="new_block_notes_{{ $section->id }}" name="notes" value="{{ $isAddingBlockToThisSection ? old('notes') : '' }}" placeholder="Optional notes">

                              <div class="section-actions">
                                <button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Add Meeting Block</button>
                              </div>
                            </form>
                          </div>
                        </details>
                      </article>
                    @endforeach
                  </div>
                </article>
              @empty
                <div class="status-note">
                  No offerings match the current filter. Reset the filters or add a new offering to start building the term.
                </div>
              @endforelse
            </div>
          </section>
        </div>

        <aside class="studio-sidebar">
          <section class="watchlist">
            <div class="briefing-kicker">Action queue</div>
            <h2 class="watchlist-title">Sections needing attention</h2>
            <p class="watchlist-copy">Jump directly to the sections with the most unresolved issues.</p>

            @if($issueQueue->isEmpty())
              <div class="status-note mt-5">No high-priority section issues are currently open.</div>
            @else
              <div class="queue-stack">
                @foreach($issueQueue as $item)
                  <a class="queue-link" href="{{ $item['url'] }}">
                    <div>
                      <div class="queue-label">{{ $item['label'] }}</div>
                      <div class="queue-copy">{{ $item['course_title'] }}</div>
                    </div>
                    <span class="watchlist-value danger">{{ $item['issue_count'] }}</span>
                  </a>
                @endforeach
              </div>
            @endif
          </section>

          <section class="watchlist">
            <div class="briefing-kicker">Support tools</div>
            <h2 class="watchlist-title">Adjacent workflows</h2>
            <p class="watchlist-copy">Open related schedule tools without losing the active term context.</p>

            <div class="studio-support">
              @foreach($supportLinks as $link)
                <a href="{{ $link['href'] }}">{{ $link['label'] }}</a>
              @endforeach
            </div>
          </section>
        </aside>
      </section>
    @endif
  </div>
</x-aop-layout>
