<x-aop-layout :activeTermLabel="($term ? 'Active Term: '.$term->code.' - '.$term->name : 'No active term selected')">
  <x-slot:title>Schedule</x-slot:title>

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
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Schedule workspace</div>
        <h1 class="briefing-title">{{ $term ? 'Build, validate, and publish the active term.' : 'Scheduling stays blocked until one active term is selected.' }}</h1>
        <p class="briefing-copy">
          {{ $term
              ? 'Open sections, review readiness, and publish approved snapshots for the active term.'
              : 'A single active term is required so offerings, sections, readiness checks, and publish actions all reference the same schedule automatically.' }}
        </p>

        @if ($term)
          <div class="status-ribbon">
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot bg-blue-500"></span>
              {{ $term->code }} in progress
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $blockingIssues === 0 ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
              {{ $blockingIssues }} blocking issues
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $officeHourIssues === 0 ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
              {{ $officeHourIssues }} office-hours issues
            </span>
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $summary['schedule_locked'] ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
              {{ $summary['schedule_locked'] ? 'Locked for edits' : 'Editable now' }}
            </span>
          </div>

          <div class="mt-8 quick-actions">
            <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Work in Sections</a>
            <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Open Readiness</a>
            <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Open Publish</a>
            <a class="btn secondary" href="{{ route('aop.schedule.calendar.index') }}">Calendar View</a>
          </div>
        @else
          <div class="mt-8 quick-actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Choose Active Term</a>
          </div>
        @endif
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Watchlist</div>
        <h2 class="watchlist-title">{{ $term ? ($isPublishReady ? 'Ready for release' : 'Needs attention before release') : 'Term selection required' }}</h2>
        <p class="watchlist-copy">
          {{ $term
              ? 'Current release status for this term.'
              : 'Choose the active term to continue.' }}
        </p>

        <div class="watchlist-group">
          @if ($term)
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Publication</div>
                <div class="watchlist-note">Latest released snapshot</div>
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
                <div class="watchlist-name">Blocking issues</div>
                <div class="watchlist-note">Fix these before publish</div>
              </div>
              <span class="watchlist-value {{ $blockingIssues === 0 ? 'good' : 'danger' }}">{{ $blockingIssues }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Office-hours policy</div>
                <div class="watchlist-note">Instructor coverage compliance</div>
              </div>
              <span class="watchlist-value {{ $officeHourIssues === 0 ? 'good' : 'warn' }}">{{ $officeHourIssues }}</span>
            </div>
          @else
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Current blocker</div>
                <div class="watchlist-note">Required before scheduling begins</div>
              </div>
              <span class="watchlist-value warn">No term</span>
            </div>
          @endif
        </div>
      </aside>
    </section>

    @if ($term)
      <section class="dock-grid">
        <a href="{{ route('aop.schedule.offerings.index') }}" class="dock-item">
          <div class="dock-kicker">Build</div>
          <h2 class="dock-title">Start with offerings</h2>
          <p class="dock-copy">Start with the term's offerings before moving into section and room work.</p>
          <div class="dock-meta">{{ $summary['offerings_count'] }} offerings</div>
        </a>

        <a href="{{ route('aop.schedule.sections.index') }}" class="dock-item">
          <div class="dock-kicker">Assemble</div>
          <h2 class="dock-title">Work where the schedule lives</h2>
          <p class="dock-copy">Assign instructors, manage meeting blocks, and complete room placement.</p>
          <div class="dock-meta">{{ $summary['sections_count'] }} sections</div>
        </a>

        <a href="{{ route('aop.schedule.readiness.index') }}" class="dock-item">
          <div class="dock-kicker">Validate</div>
          <h2 class="dock-title">Check readiness before release</h2>
          <p class="dock-copy">Review conflicts, missing data, and policy checks before publication.</p>
          <div class="dock-meta">{{ $blockingIssues + $officeHourIssues }} total issues</div>
        </a>

        <a href="{{ route('aop.schedule.publish.index') }}" class="dock-item">
          <div class="dock-kicker">Release</div>
          <h2 class="dock-title">Publish from one controlled lane</h2>
          <p class="dock-copy">Release the next schedule snapshot from the publish workspace.</p>
          <div class="dock-meta">{{ $summary['latest_publication_version'] ? 'Latest v'.$summary['latest_publication_version'] : 'No publication yet' }}</div>
        </a>
      </section>

      <section class="dashboard-grid">
        <div class="lg:col-span-8 sequence-board">
          <article class="sequence-item">
            <div class="sequence-head">
              <div class="flex gap-4">
                <div class="sequence-index">1</div>
                <div>
                  <div class="sequence-label">Build the working draft</div>
                  <h2 class="sequence-title">Create the structure before checking conflicts.</h2>
                  <p class="sequence-copy">Complete offerings, sections, and room assignments for the active term.</p>
                </div>
              </div>
              <div class="actions">
                <a class="btn secondary sm" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
                <a class="btn secondary sm" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
              </div>
            </div>
            <div class="sequence-strip">
              <div class="sequence-chip"><strong>{{ $summary['sections_missing_instructor_count'] }} sections missing instructor</strong> Surface assignment gaps early to reduce later rework.</div>
              <div class="sequence-chip"><strong>{{ $summary['sections_missing_meeting_blocks_count'] }} sections missing meeting blocks</strong> Missing time data blocks meaningful validation.</div>
              <div class="sequence-chip"><strong>{{ $summary['meeting_blocks_missing_room_count'] }} meeting blocks missing room</strong> Finish room assignments before validation.</div>
            </div>
          </article>

          <article class="sequence-item">
            <div class="sequence-head">
              <div class="flex gap-4">
                <div class="sequence-index">2</div>
                <div>
                  <div class="sequence-label">Validate the draft</div>
                  <h2 class="sequence-title">Resolve schedule pressure before it reaches publication.</h2>
                  <p class="sequence-copy">Review room use, instructor load, and office-hours coverage.</p>
                </div>
              </div>
              <div class="actions">
                <a class="btn secondary sm" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
                <a class="btn secondary sm" href="{{ route('aop.schedule.grids.index') }}">Grids</a>
                <a class="btn secondary sm" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
              </div>
            </div>
            <div class="sequence-strip">
              <div class="sequence-chip"><strong>{{ $summary['room_conflict_count'] }} room conflicts</strong> Resolve overlapping room assignments.</div>
              <div class="sequence-chip"><strong>{{ $summary['instructor_conflict_count'] }} instructor conflicts</strong> Scheduling stays humane and feasible when teaching collisions are surfaced early.</div>
              <div class="sequence-chip"><strong>{{ $officeHourIssues }} office-hours failures</strong> Bring office-hours coverage into compliance.</div>
            </div>
          </article>

          <article class="sequence-item">
            <div class="sequence-head">
              <div class="flex gap-4">
                <div class="sequence-index">3</div>
                <div>
                  <div class="sequence-label">Release with confidence</div>
                  <h2 class="sequence-title">Publish only when the watchlist is quiet.</h2>
                  <p class="sequence-copy">Create the next snapshot once the term is ready.</p>
                </div>
              </div>
              <div class="actions">
                <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
                <a class="btn secondary sm" href="{{ route('aop.schedule.reports.index') }}">Reports</a>
              </div>
            </div>
            <div class="sequence-strip">
              <div class="sequence-chip"><strong>{{ $summary['schedule_locked'] ? 'Locked' : 'Unlocked' }}</strong> Edit state for the current schedule.</div>
              <div class="sequence-chip"><strong>{{ str_replace('_', ' ', ucfirst($summary['term_status'])) }}</strong> Current lifecycle status.</div>
              <div class="sequence-chip"><strong>{{ $isPublishReady ? 'Ready to publish' : 'Hold release' }}</strong> Release status for the current term.</div>
            </div>
          </article>
        </div>

      <aside class="lg:col-span-4 watchlist">
        <div class="briefing-kicker">Risk summary</div>
        <h2 class="watchlist-title">Open issues</h2>
        <p class="watchlist-copy">Open issues that still need attention.</p>

          <div class="watchlist-group">
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Missing instructors</div>
                <div class="watchlist-note">Assignment gaps slow scheduling</div>
              </div>
              <span class="watchlist-value {{ $summary['sections_missing_instructor_count'] === 0 ? 'good' : 'warn' }}">{{ $summary['sections_missing_instructor_count'] }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Missing meeting blocks</div>
                <div class="watchlist-note">Sections still missing scheduled times</div>
              </div>
              <span class="watchlist-value {{ $summary['sections_missing_meeting_blocks_count'] === 0 ? 'good' : 'warn' }}">{{ $summary['sections_missing_meeting_blocks_count'] }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Room conflicts</div>
                <div class="watchlist-note">Overlapping room assignments</div>
              </div>
              <span class="watchlist-value {{ $summary['room_conflict_count'] === 0 ? 'good' : 'danger' }}">{{ $summary['room_conflict_count'] }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Instructor conflicts</div>
                <div class="watchlist-note">Teaching overlaps and office-hour collisions</div>
              </div>
              <span class="watchlist-value {{ $summary['instructor_conflict_count'] === 0 ? 'good' : 'danger' }}">{{ $summary['instructor_conflict_count'] }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Office-hours failures</div>
                <div class="watchlist-note">Coverage requirements still failing</div>
              </div>
              <span class="watchlist-value {{ $officeHourIssues === 0 ? 'good' : 'warn' }}">{{ $officeHourIssues }}</span>
            </div>
          </div>
        </aside>
      </section>
    @else
      <section class="sequence-item">
        <div class="sequence-head">
          <div class="flex gap-4">
            <div class="sequence-index">1</div>
            <div>
              <div class="sequence-label">Required first move</div>
              <h2 class="sequence-title">Set the active term before opening schedule tools.</h2>
              <p class="sequence-copy">Once the active term is set, schedule, readiness, and publish tools all use it automatically.</p>
            </div>
          </div>
          <div class="actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
          </div>
        </div>
      </section>
    @endif
  </div>
</x-aop-layout>
