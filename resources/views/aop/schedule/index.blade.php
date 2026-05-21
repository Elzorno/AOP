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

    $validateDone  = $term && $blockingIssues === 0 && $officeHourIssues === 0;
    $publishDone   = $term && !empty($summary['latest_publication_version']);

    $dayAbbr = [
        'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
        'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun',
    ];
  @endphp

  <div class="page-shell">

    {{-- ── COMPACT PAGE HEADER ─────────────────────────────────────────────── --}}
    <div class="sched-header">
      <div class="sched-header-main">
        <div class="sched-header-eyebrow">Schedule Studio</div>
        <h1 class="sched-header-title">
          {{ $term ? $term->code.' — '.$term->name : 'No active term set' }}
        </h1>
        @if ($term)
          <div class="sched-header-stats">
            <span>{{ $summary['offerings_count'] }} offering{{ $summary['offerings_count'] === 1 ? '' : 's' }}</span>
            <span class="sched-stat-sep">·</span>
            <span>{{ $summary['sections_count'] }} section{{ $summary['sections_count'] === 1 ? '' : 's' }}</span>
            <span class="sched-stat-sep">·</span>
            @if ($conflictCount > 0)
              <span class="sched-stat-danger">{{ $conflictCount }} conflict{{ $conflictCount === 1 ? '' : 's' }}</span>
            @else
              <span class="sched-stat-good">No conflicts</span>
            @endif
            @if ($missingCount > 0)
              <span class="sched-stat-sep">·</span>
              <span class="sched-stat-warn">{{ $missingCount }} missing</span>
            @endif
            <span class="sched-stat-sep">·</span>
            <span class="{{ $scheduleLocked ? 'sched-stat-warn' : 'sched-stat-muted' }}">{{ $scheduleLocked ? 'Locked' : 'Editable' }}</span>
            @if ($term->enforce_schedule_blocks)
              <span class="sched-stat-sep">·</span>
              <span class="sched-stat-warn" title="Non-canonical blocks are rejected as hard errors">Blocks enforced</span>
            @endif
          </div>
        @else
          <p class="sched-header-copy">Set an active term to begin building the schedule.</p>
        @endif
      </div>
      <div class="sched-header-actions">
        @if ($term)
          @if ($isPublishReady && !$scheduleLocked)
            <a class="btn success" href="{{ route('aop.schedule.publish.index') }}">Ready to publish</a>
          @elseif ($blockingIssues > 0 || $officeHourIssues > 0)
            <a class="btn" href="{{ route('aop.schedule.readiness.index') }}">
              Review {{ $blockingIssues + $officeHourIssues }} issue{{ ($blockingIssues + $officeHourIssues) === 1 ? '' : 's' }}
            </a>
          @endif
          <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
          <a class="btn secondary" href="{{ route('aop.schedule.calendar.index') }}">Calendar</a>
          <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Grids</a>
          <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
        @else
          <a class="btn" href="{{ route('aop.terms.index') }}">Manage Terms</a>
        @endif
      </div>
    </div>

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

      {{-- Hidden data store for client-side conflict detection --}}
      <div id="sched-blocks-data" data-blocks="{{ json_encode($meetingBlocksJson) }}" style="display:none;"></div>

      {{-- ── STAGE TRACK ──────────────────────────────────────────────────── --}}
      <nav class="stage-track" aria-label="Scheduling workflow stages">
        <span class="stage-step stage-step-active" aria-current="step">
          <span class="stage-step-icon">1</span>
          <span class="stage-step-label">Build</span>
          <span class="stage-step-note">Offerings, sections &amp; meetings</span>
        </span>
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

      {{-- ── DISTRIBUTION HEALTH ─────────────────────────────────────────── --}}
      @if ($distributionStats && $distributionStats['totals']['sections'] > 0)
        @php
          $df = $distributionStats['flags'];
          $dp = $distributionStats;
          $anyDistFlag = $df['friday_low'] || $df['peak_high'] || $df['mwf_high'] || $df['tr_high']
                      || $df['gep_online_high'] || $df['prog_online_high'];
        @endphp
        <div class="dist-health-bar">
          <span class="dist-health-label">
            Distribution
            @if ($anyDistFlag)
              <span class="dist-health-badge warn">{{ collect($df)->filter()->count() }} target{{ collect($df)->filter()->count() === 1 ? '' : 's' }} missed</span>
            @else
              <span class="dist-health-badge good">On target</span>
            @endif
          </span>

          <span class="dist-stat {{ $df['friday_low'] ? 'dist-stat-warn' : 'dist-stat-good' }}" title="Target: ≥ 12% of sections have a Friday meeting">
            <span class="dist-stat-value">{{ number_format($dp['friday_pct'], 1) }}%</span>
            <span class="dist-stat-label">Friday <span class="dist-stat-target">≥12%</span></span>
          </span>

          <span class="dist-stat {{ $df['peak_high'] ? 'dist-stat-danger' : 'dist-stat-good' }}" title="Target: ≤ 60% of classroom minutes 9:30 am–3:00 pm">
            <span class="dist-stat-value">{{ number_format($dp['peak_hour_pct'], 1) }}%</span>
            <span class="dist-stat-label">Peak hrs <span class="dist-stat-target">≤60%</span></span>
          </span>

          <span class="dist-stat {{ $df['mwf_high'] ? 'dist-stat-danger' : 'dist-stat-good' }}" title="Target: ≤ 60% of classroom minutes on M/W/F">
            <span class="dist-stat-value">{{ number_format($dp['mwf_pct'], 1) }}%</span>
            <span class="dist-stat-label">MWF <span class="dist-stat-target">≤60%</span></span>
          </span>

          <span class="dist-stat {{ $df['tr_high'] ? 'dist-stat-danger' : 'dist-stat-good' }}" title="Target: ≤ 60% of classroom minutes on T/R">
            <span class="dist-stat-value">{{ number_format($dp['tr_pct'], 1) }}%</span>
            <span class="dist-stat-label">TR <span class="dist-stat-target">≤60%</span></span>
          </span>

          @if ($dp['totals']['gep_total'] > 0)
            <span class="dist-stat {{ $df['gep_online_high'] ? 'dist-stat-danger' : 'dist-stat-good' }}" title="Target: ≤ 40% of GEP sections offered online ({{ $dp['totals']['gep_online'] }}/{{ $dp['totals']['gep_total'] }} GEP sections)">
              <span class="dist-stat-value">{{ number_format($dp['gep_online_pct'], 1) }}%</span>
              <span class="dist-stat-label">GEP online <span class="dist-stat-target">≤40%</span></span>
            </span>
          @endif

          @if ($dp['totals']['program_total'] > 0)
            <span class="dist-stat {{ $df['prog_online_high'] ? 'dist-stat-danger' : 'dist-stat-good' }}" title="Target: ≤ 25% of required program sections offered online ({{ $dp['totals']['program_online'] }}/{{ $dp['totals']['program_total'] }} program sections)">
              <span class="dist-stat-value">{{ number_format($dp['program_online_pct'], 1) }}%</span>
              <span class="dist-stat-label">Program online <span class="dist-stat-target">≤25%</span></span>
            </span>
          @endif

          <a class="dist-health-detail-link" href="{{ route('aop.schedule.readiness.index') }}#distribution">Details</a>
        </div>
      @endif

      {{-- ── LOCK BANNER ──────────────────────────────────────────────────── --}}
      @if ($scheduleLocked)
        <div class="lock-banner" role="alert">
          <svg class="lock-banner-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          <span class="lock-banner-text">The schedule is locked. Unlock the term before creating or editing offerings, sections, or meeting blocks.</span>
        </div>
      @endif

      {{-- ── ISSUE ALERT STRIP (replaces sidebar action queue) ────────────── --}}
      @if (!$issueQueue->isEmpty())
        <div class="issue-alert-strip" role="status">
          <span class="issue-alert-label">
            <svg class="h-4 w-4 flex-shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            {{ $issueQueue->count() }} section{{ $issueQueue->count() === 1 ? '' : 's' }} need attention
          </span>
          <span class="issue-alert-list">
            @foreach($issueQueue->take(5) as $item)
              <a class="issue-alert-item" href="{{ $item['url'] }}">
                {{ $item['label'] }}<span class="issue-alert-count">{{ $item['issue_count'] }}</span>
              </a>
            @endforeach
          </span>
          <a class="issue-alert-more" href="{{ route('aop.schedule.readiness.index') }}">Review all →</a>
        </div>
      @endif

      {{-- ── ADD OFFERING ────────────────────────────────────────────────── --}}
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

      {{-- ── ACTIVE SCHEDULE ─────────────────────────────────────────────── --}}
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

        {{-- Offering stack --}}
        <div class="offering-stack">
          @forelse($offerings as $offeringCard)
            @php
              $offering = $offeringCard['model'];
              $course   = $offeringCard['course'];

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

                  @include('aop.schedule.partials.section-card', [
                    'section'          => $section,
                    'sectionCard'      => $sectionCard,
                    'course'           => $course,
                    'rooms'            => $rooms,
                    'instructors'      => $instructors,
                    'modalities'       => $modalities,
                    'meetingBlockTypes'=> $meetingBlockTypes,
                    'weekDays'         => $weekDays,
                    'scheduleLocked'   => $scheduleLocked,
                    'focusSectionId'   => $focusSectionId,
                    'dayAbbr'          => $dayAbbr,
                    'htmxError'        => null,
                    'oldMeetingBlockId'=> $oldMeetingBlockId,
                  ])
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
              No offerings match the current filter or page. Reset the filters or add a new offering above.
            </div>
          @endforelse

          @if ($offerings->hasPages())
            <div class="mt-6 flex justify-center">
              {{ $offerings->links() }}
            </div>
          @endif
        </div>
      </section>

    @endif{{-- end $term check --}}
  </div>
</x-aop-layout>
