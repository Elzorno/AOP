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
    $conflictCount   = $summary ? ($summary['room_conflict_count'] + $summary['instructor_conflict_count']) : 0;
    $missingCount    = $summary
        ? $summary['sections_missing_instructor_count']
            + $summary['sections_missing_meeting_blocks_count']
            + $summary['meeting_blocks_missing_room_count']
        : 0;
    $officeHourIssues = $summary['office_hours_failing_count'] ?? 0;
    $isPublishReady   = $summary && $blockingIssues === 0 && $officeHourIssues === 0;
    $scheduleLocked   = (bool) ($summary['schedule_locked'] ?? false);

    $oldOfferingId        = old('offering_id') ? (int) old('offering_id') : null;
    $oldSectionId         = old('section_id') ? (int) old('section_id') : null;
    $oldMeetingSectionId  = old('meeting_section_id') ? (int) old('meeting_section_id') : null;
    $oldMeetingBlockId    = old('meeting_block_id') ? (int) old('meeting_block_id') : null;
    $hasOfferingDraft     = collect([
        old('catalog_course_id'),
        old('delivery_method'),
        old('prereq_override'),
        old('coreq_override'),
    ])->filter(fn ($value) => filled($value))->isNotEmpty();

    /* Stage: Build = this page; Validate = readiness; Publish = publish */
    $validateDone  = $term && $blockingIssues === 0 && $officeHourIssues === 0;
    $publishDone   = $term && !empty($summary['latest_publication_version']);

    /* Day abbreviations for meeting chips */
    $dayAbbr = [
        'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
        'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun',
    ];
  @endphp

  <div class="page-shell">

    {{-- ── 1. TERM CONTEXT HEADER ─────────────────────────────────────────── --}}
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Schedule Studio</div>
        <h1 class="briefing-title">
          {{ $term ? $term->code.' — '.$term->name : 'No active term' }}
        </h1>
        <p class="briefing-copy">
          {{ $term
              ? 'Build offerings, sections, and meeting blocks for the active term.'
              : 'Set an active term to begin scheduling.' }}
        </p>

        @if ($term)
          <div class="status-ribbon">
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot bg-blue-400"></span>
              {{ $summary['offerings_count'] }} offering{{ $summary['offerings_count'] === 1 ? '' : 's' }}
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot bg-slate-400"></span>
              {{ $summary['sections_count'] }} section{{ $summary['sections_count'] === 1 ? '' : 's' }}
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $conflictCount === 0 ? 'bg-emerald-400' : 'bg-red-500' }}"></span>
              {{ $conflictCount }} conflict{{ $conflictCount === 1 ? '' : 's' }}
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $missingCount === 0 ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
              {{ $missingCount }} missing
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $scheduleLocked ? 'bg-amber-400' : 'bg-emerald-400' }}"></span>
              {{ $scheduleLocked ? 'Locked' : 'Editable' }}
            </span>
          </div>

          <div class="mt-6 quick-actions">
            <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
            <a class="btn secondary" href="{{ route('aop.schedule.calendar.index') }}">Calendar</a>
            <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Grids</a>
            <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
          </div>
        @else
          <div class="mt-6 quick-actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Manage Terms</a>
          </div>
        @endif
      </div>

      {{-- Readiness watchlist sidebar --}}
      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Readiness snapshot</div>
        <h2 class="watchlist-title">
          {{ $term
              ? ($isPublishReady ? 'All clear' : 'Needs attention')
              : 'No active term' }}
        </h2>
        <p class="watchlist-copy">
          {{ $term
              ? 'Current blocking issues and term state.'
              : 'Scheduling requires an active term.' }}
        </p>

        @if ($term)
          <div class="watchlist-group">
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Conflicts</div>
                <div class="watchlist-note">Room + instructor overlap</div>
              </div>
              <span class="watchlist-value {{ $conflictCount === 0 ? 'good' : 'danger' }}">{{ $conflictCount }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Missing data</div>
                <div class="watchlist-note">Instructor · meetings · rooms</div>
              </div>
              <span class="watchlist-value {{ $missingCount === 0 ? 'good' : 'warn' }}">{{ $missingCount }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Office-hours issues</div>
                <div class="watchlist-note">Full-time coverage check</div>
              </div>
              <span class="watchlist-value {{ $officeHourIssues === 0 ? 'good' : 'warn' }}">{{ $officeHourIssues }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Published</div>
                <div class="watchlist-note">Latest snapshot</div>
              </div>
              <span class="watchlist-value {{ $summary['latest_publication_version'] ? 'good' : 'warn' }}">
                {{ $summary['latest_publication_version'] ? 'v'.$summary['latest_publication_version'] : 'None' }}
              </span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Term status</div>
                <div class="watchlist-note">Lifecycle state</div>
              </div>
              <span class="watchlist-value {{ $summary['term_status'] === 'published' ? 'good' : 'warn' }}">
                {{ str_replace('_', ' ', ucfirst($summary['term_status'])) }}
              </span>
            </div>
          </div>

          @if ($isPublishReady)
            <div class="mt-5">
              <a class="btn success" href="{{ route('aop.schedule.publish.index') }}">Go to Publish</a>
            </div>
          @elseif ($blockingIssues > 0 || $officeHourIssues > 0)
            <div class="mt-5">
              <a class="btn" href="{{ route('aop.schedule.readiness.index') }}">Review Readiness</a>
            </div>
          @endif
        @else
          <div class="watchlist-group">
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Blocker</div>
                <div class="watchlist-note">No term is set active</div>
              </div>
              <span class="watchlist-value warn">Unset</span>
            </div>
          </div>
        @endif
      </aside>
    </section>

    @if (!$term)
      <section class="workspace-card">
        <div class="workspace-header">
          <div>
            <h2 class="workspace-title">Active term required</h2>
            <p class="workspace-copy">Set a term as active, then return here to build the schedule.</p>
          </div>
          <div class="actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Manage Terms</a>
          </div>
        </div>
      </section>
    @else

      {{-- ── 2. STAGE TRACK ──────────────────────────────────────────────────── --}}
      <nav class="stage-track" aria-label="Scheduling workflow stages">
        {{-- Build --}}
        <span class="stage-step stage-step-active" aria-current="step">
          <span class="stage-step-icon">1</span>
          <span class="stage-step-label">Build</span>
          <span class="stage-step-note">Offerings, sections &amp; meetings</span>
        </span>

        {{-- Validate --}}
        <a
          href="{{ route('aop.schedule.readiness.index') }}"
          class="stage-step {{ $validateDone ? 'stage-step-done' : '' }}"
        >
          <span class="stage-step-icon">
            @if ($validateDone)
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            @else
              2
            @endif
          </span>
          <span class="stage-step-label">Validate</span>
          <span class="stage-step-note">Conflicts, minutes &amp; hours</span>
        </a>

        {{-- Publish --}}
        <a
          href="{{ route('aop.schedule.publish.index') }}"
          class="stage-step {{ $publishDone ? 'stage-step-done' : '' }}"
        >
          <span class="stage-step-icon">
            @if ($publishDone)
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            @else
              3
            @endif
          </span>
          <span class="stage-step-label">Publish</span>
          <span class="stage-step-note">Lock &amp; snapshot</span>
        </a>
      </nav>

      {{-- ── 3. READINESS PULSE BAR ──────────────────────────────────────────── --}}
      <div class="readiness-bar" role="status" aria-label="Schedule readiness summary">
        <span class="readiness-bar-label">Status</span>

        <span class="readiness-stat {{ $summary['offerings_count'] > 0 ? 'r-good' : '' }}">
          <span class="readiness-stat-dot {{ $summary['offerings_count'] > 0 ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
          {{ $summary['offerings_count'] }} offering{{ $summary['offerings_count'] === 1 ? '' : 's' }}
        </span>

        <span class="readiness-stat {{ $summary['sections_count'] > 0 ? 'r-good' : '' }}">
          <span class="readiness-stat-dot {{ $summary['sections_count'] > 0 ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
          {{ $summary['sections_count'] }} section{{ $summary['sections_count'] === 1 ? '' : 's' }}
        </span>

        @if ($conflictCount > 0)
          <span class="readiness-stat r-danger">
            <span class="readiness-stat-dot bg-red-500"></span>
            {{ $conflictCount }} conflict{{ $conflictCount === 1 ? '' : 's' }}
          </span>
        @else
          <span class="readiness-stat r-good">
            <span class="readiness-stat-dot bg-emerald-500"></span>
            No conflicts
          </span>
        @endif

        @if ($missingCount > 0)
          <span class="readiness-stat r-warn">
            <span class="readiness-stat-dot bg-amber-400"></span>
            {{ $missingCount }} missing
          </span>
        @else
          <span class="readiness-stat r-good">
            <span class="readiness-stat-dot bg-emerald-500"></span>
            Complete
          </span>
        @endif

        @if ($scheduleLocked)
          <span class="readiness-stat r-warn">
            <span class="readiness-stat-dot bg-amber-400"></span>
            Locked
          </span>
        @else
          <span class="readiness-stat r-good">
            <span class="readiness-stat-dot bg-emerald-500"></span>
            Editable
          </span>
        @endif

        <div class="readiness-bar-actions">
          @if ($isPublishReady && !$scheduleLocked)
            <a class="btn success" href="{{ route('aop.schedule.publish.index') }}">Ready to publish</a>
          @elseif ($blockingIssues > 0 || $officeHourIssues > 0)
            <a class="btn" href="{{ route('aop.schedule.readiness.index') }}">
              Review {{ $blockingIssues + $officeHourIssues }} issue{{ ($blockingIssues + $officeHourIssues) === 1 ? '' : 's' }}
            </a>
          @endif
        </div>
      </div>

      {{-- ── 4. LOCK BANNER ──────────────────────────────────────────────────── --}}
      @if ($scheduleLocked)
        <div class="lock-banner" role="alert">
          <svg class="lock-banner-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          <span class="lock-banner-text">The schedule is locked. Unlock the term before creating or editing offerings, sections, or meeting blocks.</span>
        </div>
      @endif

      {{-- ── 5. STUDIO GRID ──────────────────────────────────────────────────── --}}
      <section class="studio-grid">
        <div class="studio-main">

          {{-- ── 5a. ADD OFFERING ── --}}
          <details
            class="add-offering-disclosure"
            {{ $hasOfferingDraft || ($errors->any() && !old('section_id') && !old('meeting_section_id')) ? 'open' : '' }}
          >
            <summary class="add-offering-summary">
              <div class="add-offering-summary-inner">
                <span class="add-offering-summary-icon">+</span>
                <div>
                  <div class="add-offering-summary-title">Add offering to {{ $term->code }}</div>
                  <div class="add-offering-summary-note">
                    {{ $availableCourses->isEmpty()
                        ? 'All active courses are offered this term'
                        : $availableCourses->count().' course'.($availableCourses->count() === 1 ? '' : 's').' available' }}
                  </div>
                </div>
              </div>
              <span class="add-offering-summary-chevron" aria-hidden="true">+</span>
            </summary>

            <div class="add-offering-body">
              <div class="stack-grid-2">
                <form method="POST" action="{{ route('aop.schedule.offerings.store') }}" class="surface-note">
                  @csrf
                  <input type="hidden" name="from_schedule_home" value="1">

                  <label for="catalog_course_id">Catalog course</label>
                  <select id="catalog_course_id" name="catalog_course_id" required>
                    <option value="" disabled {{ old('catalog_course_id') ? '' : 'selected' }}>
                      {{ $availableCourses->isEmpty() ? 'All active courses are already offered' : 'Choose a course…' }}
                    </option>
                    @foreach($availableCourses as $course)
                      <option value="{{ $course->id }}" {{ (string) old('catalog_course_id') === (string) $course->id ? 'selected' : '' }}>
                        {{ $course->code }} — {{ $course->title }}
                      </option>
                    @endforeach
                  </select>

                  <div class="inline-form-grid-2">
                    <div>
                      <label for="delivery_method">Delivery method</label>
                      <input id="delivery_method" name="delivery_method" value="{{ old('delivery_method') }}" placeholder="Lecture, Lab, Hybrid…">
                    </div>
                    <div>
                      <label for="offering_notes">Notes</label>
                      <input id="offering_notes" name="notes" value="{{ old('notes') }}" placeholder="Optional planning notes">
                    </div>
                  </div>

                  <details class="disclosure" {{ $hasOfferingDraft ? 'open' : '' }}>
                    <summary class="disclosure-summary">Prerequisite &amp; corequisite overrides</summary>
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
                  <strong class="text-slate-800 text-sm">Scheduling workflow</strong>
                  <ol class="mt-3 space-y-2 text-sm leading-6 text-slate-600 list-decimal list-inside">
                    <li>Add an offering for the active term.</li>
                    <li>Add sections inside each offering card below.</li>
                    <li>Add meeting blocks and resolve conflicts inline.</li>
                    <li>Review Readiness, then lock and publish.</li>
                  </ol>
                  @if($availableCourses->isEmpty())
                    <div class="status-note mt-4">Every active catalog course already has an offering this term. Add more sections below, or activate additional courses in Catalog.</div>
                  @endif
                </div>
              </div>
            </div>
          </details>

          {{-- ── 5b. CURRENT SCHEDULE ── --}}
          <section class="workspace-card">
            <div class="workspace-header">
              <div>
                <div class="briefing-kicker">Active schedule</div>
                <h2 class="workspace-title">Offerings &amp; sections</h2>
                <p class="workspace-copy">View and edit all offerings, sections, and meeting blocks.</p>
              </div>
            </div>

            {{-- Filter bar --}}
            <form method="GET" action="{{ route('aop.schedule.home') }}">
              <div class="filter-grid">
                <div class="md:col-span-2 xl:col-span-2">
                  <label for="q">Search</label>
                  <input id="q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Course code, section, instructor…">
                </div>
                <div>
                  <label for="issue">Filter by state</label>
                  <select id="issue" name="issue">
                    <option value="all"               {{ $filters['issue'] === 'all'                ? 'selected' : '' }}>All sections</option>
                    <option value="attention"         {{ $filters['issue'] === 'attention'          ? 'selected' : '' }}>Needs attention</option>
                    <option value="missing_instructor"{{ $filters['issue'] === 'missing_instructor' ? 'selected' : '' }}>Missing instructor</option>
                    <option value="missing_meetings"  {{ $filters['issue'] === 'missing_meetings'   ? 'selected' : '' }}>Missing meetings</option>
                    <option value="missing_room"      {{ $filters['issue'] === 'missing_room'       ? 'selected' : '' }}>Missing room</option>
                    <option value="conflicts"         {{ $filters['issue'] === 'conflicts'          ? 'selected' : '' }}>Conflicts only</option>
                    <option value="ready"             {{ $filters['issue'] === 'ready'              ? 'selected' : '' }}>Ready</option>
                  </select>
                </div>
                <div class="actions md:items-end">
                  <button class="btn secondary" type="submit">Apply</button>
                  <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Reset</a>
                </div>
              </div>
            </form>

            {{-- ── OFFERING STACK ── --}}
            <div class="offering-stack">
              @forelse($offerings as $offeringCard)
                @php
                  $offering = $offeringCard['model'];
                  $course   = $offeringCard['course'];

                  /* Determine offering card state: danger > warn > ready */
                  $hasConflicts = false;
                  foreach ($offeringCard['sections'] as $sc) {
                      if (!empty($sc['conflict_notes'])) { $hasConflicts = true; break; }
                  }
                  $offeringStateClass = $hasConflicts
                      ? 'offering-card-danger'
                      : ($offeringCard['issue_count'] > 0 ? 'offering-card-warn' : 'offering-card-ready');
                @endphp

                <article
                  id="offering-{{ $offering->id }}"
                  class="offering-card {{ $offeringCard['focus'] ? 'offering-card-focus' : '' }} {{ $offeringStateClass }}"
                >
                  {{-- Offering header --}}
                  <div class="offering-compact-head">
                    <div class="min-w-0">
                      <div class="offering-course-code">{{ $course?->code ?? 'NO CODE' }}</div>
                      <h3 class="offering-course-title">{{ $course?->title ?? 'Untitled course' }}</h3>
                      <p class="offering-course-sub">{{ $offering->delivery_method ?: 'Delivery method not set' }}</p>
                    </div>
                    <div class="offering-pill-row flex-shrink-0">
                      <span class="offering-pill">{{ $offeringCard['all_sections_count'] }} section{{ $offeringCard['all_sections_count'] === 1 ? '' : 's' }}</span>
                      @if ($offeringCard['issue_count'] > 0)
                        <span class="badge danger">{{ $offeringCard['issue_count'] }} issue{{ $offeringCard['issue_count'] === 1 ? '' : 's' }}</span>
                      @else
                        <span class="badge success">Ready</span>
                      @endif
                      @if ($offeringCard['has_empty_offering'])
                        <span class="badge warn">No sections</span>
                      @endif
                      @if ($offeringCard['all_sections_count'] === 0 && !$scheduleLocked)
                        <form method="POST" action="{{ route('aop.schedule.offerings.destroy', $offering) }}" onsubmit="return confirm('Remove offering for {{ addslashes($course?->code ?? 'this course') }}? This cannot be undone.')">
                          @csrf
                          @method('DELETE')
                          <input type="hidden" name="from_schedule_home" value="1">
                          <button type="submit" class="btn link-danger sm" title="Remove this empty offering">Remove</button>
                        </form>
                      @endif
                    </div>
                  </div>

                  {{-- Section stack --}}
                  @if($offeringCard['sections'] === [])
                    <div class="status-note mt-4">No sections match the current filter for this offering.</div>
                  @endif

                  <div class="section-stack">
                    @foreach($offeringCard['sections'] as $sectionCard)
                      @php
                        $section              = $sectionCard['model'];
                        $isEditingThisSection = $oldSectionId === $section->id;
                        $isAddingBlockHere    = $oldMeetingSectionId === $section->id && !$oldMeetingBlockId;
                        $hasIssues            = !empty($sectionCard['conflict_notes']) || count(array_filter($sectionCard['issue_badges'], fn($b) => $b['tone'] !== 'good')) > 0;
                      @endphp

                      <article
                        id="section-{{ $section->id }}"
                        class="section-card {{ $focusSectionId === $section->id ? 'section-card-focus' : '' }}"
                      >
                        {{-- Section row header --}}
                        <div class="section-head">
                          <div class="min-w-0">
                            <h4 class="section-title">
                              {{ $course?->code ?? '' }} · Section {{ $section->section_code }}
                            </h4>
                            <div class="section-info-row">
                              {{-- Instructor chip --}}
                              <span class="section-info-chip {{ $section->instructor_id ? '' : 'chip-unset' }}">
                                {{ $section->instructor?->name ?? 'Instructor unassigned' }}
                              </span>
                              {{-- Modality chip --}}
                              <span class="section-info-chip">{{ $section->modality->value }}</span>
                              {{-- Meeting count chip --}}
                              <span class="section-info-chip {{ count($sectionCard['meeting_cards']) === 0 ? 'chip-unset' : '' }}">
                                {{ count($sectionCard['meeting_cards']) }} meeting block{{ count($sectionCard['meeting_cards']) === 1 ? '' : 's' }}
                              </span>
                            </div>
                          </div>
                          {{-- Status badges --}}
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

                        {{-- Block edit rows: all meeting block edits + add + section edit grouped compactly --}}
                        <div class="block-edit-rows">

                          {{-- Per-block edit rows --}}
                          @foreach($sectionCard['meeting_cards'] as $meetingCard)
                            @php $meetingBlock = $meetingCard['model']; @endphp
                            <details class="block-edit-row" {{ $oldMeetingBlockId === $meetingBlock->id ? 'open' : '' }}>
                              <summary class="disclosure-summary">
                                Edit {{ $meetingBlock->type->value }}
                                · {{ implode(' ', array_map(fn($d) => $dayAbbr[$d] ?? $d, $meetingBlock->days_json ?? [])) ?: 'No days' }}
                                · {{ substr($meetingBlock->starts_at,0,5) }}–{{ substr($meetingBlock->ends_at,0,5) }}
                                @if($meetingBlock->room) · {{ $meetingBlock->room->name }}@endif
                              </summary>
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
                                          <option value="{{ $room->id }}" {{ (string) ($oldMeetingBlockId === $meetingBlock->id ? old('room_id') : $meetingBlock->room_id) === (string) $room->id ? 'selected' : '' }}>{{ $room->name }}</option>
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
                                    <button class="btn secondary" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save meeting block</button>
                                  </div>
                                </form>
                              </div>
                            </details>
                          @endforeach

                          {{-- Add meeting block row --}}
                          <details class="block-edit-row" {{ $focusSectionId === $section->id || $sectionCard['has_missing_meetings'] || $isAddingBlockHere ? 'open' : '' }}>
                            <summary class="disclosure-summary">+ Add meeting block</summary>
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
                                        <option value="{{ $type->value }}" {{ ($isAddingBlockHere ? old('type') : null) === $type->value ? 'selected' : '' }}>{{ $type->value }}</option>
                                      @endforeach
                                    </select>
                                  </div>
                                  <div>
                                    <label for="new_block_room_{{ $section->id }}">Room</label>
                                    <select id="new_block_room_{{ $section->id }}" name="room_id">
                                      <option value="">No room</option>
                                      @foreach($rooms as $room)
                                        <option value="{{ $room->id }}" {{ (string) ($isAddingBlockHere ? old('room_id') : null) === (string) $room->id ? 'selected' : '' }}>{{ $room->name }}</option>
                                      @endforeach
                                    </select>
                                  </div>
                                  <div>
                                    <label for="new_block_start_{{ $section->id }}">Start</label>
                                    <input id="new_block_start_{{ $section->id }}" type="time" name="starts_at" value="{{ $isAddingBlockHere ? old('starts_at') : '' }}" required>
                                  </div>
                                  <div>
                                    <label for="new_block_end_{{ $section->id }}">End</label>
                                    <input id="new_block_end_{{ $section->id }}" type="time" name="ends_at" value="{{ $isAddingBlockHere ? old('ends_at') : '' }}" required>
                                  </div>
                                </div>

                                <label>Days</label>
                                <div class="inline-form-grid-3">
                                  @foreach($weekDays as $day)
                                    <label class="checkbox-row">
                                      <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $isAddingBlockHere ? (old('days', []) ?: []) : [], true) ? 'checked' : '' }}>
                                      <span>{{ $day }}</span>
                                    </label>
                                  @endforeach
                                </div>

                                <label for="new_block_notes_{{ $section->id }}">Notes</label>
                                <input id="new_block_notes_{{ $section->id }}" name="notes" value="{{ $isAddingBlockHere ? old('notes') : '' }}" placeholder="Optional notes">

                                <div class="section-actions">
                                  <button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Add meeting block</button>
                                </div>
                              </form>
                            </div>
                          </details>

                          {{-- Edit section row --}}
                          <details class="block-edit-row" {{ $focusSectionId === $section->id || $isEditingThisSection ? 'open' : '' }}>
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
                                  <button class="btn secondary" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save section</button>
                                </div>
                              </form>
                            </div>
                          </details>

                        </div>{{-- end block-edit-rows --}}

                        {{-- Section quick-link toolbar --}}
                        <div class="section-toolbar">
                          <a class="btn secondary sm" href="{{ route('aop.schedule.sections.edit', $section) }}">Full editor</a>
                          <a class="btn secondary sm" href="{{ route('aop.syllabi.show', $section) }}">Syllabus</a>
                          @if($section->instructor_id)
                            <a class="btn secondary sm" href="{{ route('aop.schedule.officeHours.show', $section->instructor_id) }}">Office hours</a>
                          @endif
                        </div>

                      </article>
                    @endforeach
                  </div>

                  {{-- Add section disclosure --}}
                  <details class="disclosure" {{ $offeringCard['focus'] || $offeringCard['has_empty_offering'] || $oldOfferingId === $offering->id ? 'open' : '' }}>
                    <summary class="disclosure-summary">+ Add section to this offering</summary>
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

                </article>
              @empty
                <div class="status-note mt-2">
                  No offerings match the current filter. Reset the filters or add a new offering above.
                </div>
              @endforelse
            </div>
          </section>

        </div>{{-- end studio-main --}}

        {{-- ── STUDIO SIDEBAR ── --}}
        <aside class="studio-sidebar">

          {{-- Action queue --}}
          <section class="watchlist">
            <div class="briefing-kicker">Action queue</div>
            <h2 class="watchlist-title">Needs attention</h2>
            <p class="watchlist-copy">Sections with the highest issue counts.</p>

            @if($issueQueue->isEmpty())
              <div class="status-note mt-5">No high-priority section issues open.</div>
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

          {{-- Schedule tools --}}
          <section class="watchlist">
            <div class="briefing-kicker">Tools</div>
            <h2 class="watchlist-title">Schedule tools</h2>
            <p class="watchlist-copy">Related views and actions.</p>

            <div class="studio-support">
              @foreach($supportLinks as $link)
                <a href="{{ $link['href'] }}">{{ $link['label'] }}</a>
              @endforeach
            </div>
          </section>

        </aside>
      </section>{{-- end studio-grid --}}

    @endif{{-- end $term check --}}
  </div>
</x-aop-layout>
