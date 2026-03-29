<x-aop-layout :activeTermLabel="$activeTerm ? 'Active Term: '.$activeTerm->code.' - '.$activeTerm->name : 'No active term selected'">
  <x-slot:title>Dashboard</x-slot:title>

  @php
    $isReadyToSchedule = $activeTerm && $counts['catalog_courses'] > 0 && $counts['instructors'] > 0 && $counts['rooms'] > 0;
    $nextActionLabel = $activeTerm ? 'Open Schedule' : 'Set Active Term';
    $nextActionRoute = $activeTerm ? route('aop.schedule.home') : route('aop.terms.index');
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Dashboard</div>
        <h1 class="briefing-title">{{ $activeTerm ? $activeTerm->code.' overview' : 'Set the active term' }}</h1>
        <p class="briefing-copy">
          {{ $activeTerm
              ? 'Review setup and continue scheduling.'
              : 'Choose the term before scheduling.' }}
        </p>

        <div class="status-ribbon">
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $activeTerm ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
            {{ $activeTerm ? $activeTerm->code.' in focus' : 'No active term selected' }}
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $isReadyToSchedule ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
            {{ $isReadyToSchedule ? 'Core setup is ready' : 'Setup data still needs review' }}
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot bg-blue-500"></span>
            {{ number_format($counts['sections']) }} total sections on file
          </span>
        </div>

        <div class="mt-8 quick-actions">
          <a class="btn" href="{{ $nextActionRoute }}">{{ $nextActionLabel }}</a>
          <a class="btn secondary" href="{{ route('aop.terms.index') }}">Manage Terms</a>
          <a class="btn secondary" href="{{ route('aop.catalog.index') }}">Review Inputs</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Status</div>
        <h2 class="watchlist-title">Current status</h2>
        <p class="watchlist-copy">Term and setup status.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Active term</div>
              <div class="watchlist-note">{{ $activeTerm ? $activeTerm->name : 'Required before scheduling' }}</div>
            </div>
            <span class="watchlist-value {{ $activeTerm ? 'good' : 'warn' }}">{{ $activeTerm ? $activeTerm->code : 'Unset' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Fastest next step</div>
              <div class="watchlist-note">Recommended next page</div>
            </div>
            <span class="watchlist-value good">{{ $activeTerm ? 'Schedule' : 'Terms' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Setup readiness</div>
              <div class="watchlist-note">Catalog, instructors, and rooms</div>
            </div>
            <span class="watchlist-value {{ $isReadyToSchedule ? 'good' : 'warn' }}">{{ $isReadyToSchedule ? 'Ready' : 'Review' }}</span>
          </div>
        </div>
      </aside>
    </section>

    <section class="dock-grid">
      <a href="{{ route('aop.terms.index') }}" class="dock-item">
        <div class="dock-kicker">Context</div>
        <h2 class="dock-title">Terms</h2>
        <p class="dock-copy">Set the active term and manage planning cycles.</p>
        <div class="dock-meta">Open terms</div>
      </a>

      <a href="{{ route('aop.catalog.index') }}" class="dock-item">
        <div class="dock-kicker">Inputs</div>
        <h2 class="dock-title">Inputs</h2>
        <p class="dock-copy">Review catalog, instructor, and room data before creating sections.</p>
        <div class="dock-meta">Review source data</div>
      </a>

      <a href="{{ route('aop.schedule.home') }}" class="dock-item">
        <div class="dock-kicker">Execution</div>
        <h2 class="dock-title">Schedule</h2>
        <p class="dock-copy">Build, review, and publish the active term schedule.</p>
        <div class="dock-meta">Launch schedule</div>
      </a>

      <a href="{{ route('aop.syllabi.index') }}" class="dock-item">
        <div class="dock-kicker">Downstream</div>
        <h2 class="dock-title">Syllabi</h2>
        <p class="dock-copy">Manage templates, structure, and section exports.</p>
        <div class="dock-meta">Open syllabi</div>
      </a>
    </section>

    <section class="dashboard-grid">
      <div class="lg:col-span-7 sequence-board">
        <article class="sequence-item">
          <div class="sequence-head">
            <div class="flex gap-4">
              <div class="sequence-index">1</div>
              <div>
                <div class="sequence-label">Set context</div>
                <h2 class="sequence-title">Choose the active term.</h2>
                <p class="sequence-copy">Scheduling and syllabi follow this term.</p>
              </div>
            </div>
            <div class="actions">
              <a class="btn secondary sm" href="{{ route('aop.terms.index') }}">Terms</a>
              <a class="btn secondary sm" href="{{ route('aop.terms.create') }}">Create Term</a>
            </div>
          </div>
          <div class="sequence-strip">
            <div class="sequence-chip"><strong>{{ number_format($counts['terms']) }} terms</strong> Available for planning, cloning, and activation.</div>
            <div class="sequence-chip"><strong>{{ $activeTerm ? $activeTerm->code : 'No active term' }}</strong> Current active term.</div>
            <div class="sequence-chip"><strong>{{ $activeTerm ? 'Aligned' : 'Needs action' }}</strong> Schedule pages follow this term.</div>
          </div>
        </article>

        <article class="sequence-item">
          <div class="sequence-head">
            <div class="flex gap-4">
              <div class="sequence-index">2</div>
              <div>
                <div class="sequence-label">Prepare inputs</div>
                <h2 class="sequence-title">Check catalog, instructors, and rooms.</h2>
                <p class="sequence-copy">These records drive scheduling and conflict checks.</p>
              </div>
            </div>
            <div class="actions">
              <a class="btn secondary sm" href="{{ route('aop.catalog.index') }}">Catalog</a>
              <a class="btn secondary sm" href="{{ route('aop.instructors.index') }}">Instructors</a>
              <a class="btn secondary sm" href="{{ route('aop.rooms.index') }}">Rooms</a>
            </div>
          </div>
          <div class="sequence-strip">
            <div class="sequence-chip"><strong>{{ number_format($counts['catalog_courses']) }} catalog courses</strong> Source for offerings and section creation.</div>
            <div class="sequence-chip"><strong>{{ number_format($counts['instructors']) }} instructors</strong> Assignment options visible before scheduling begins.</div>
            <div class="sequence-chip"><strong>{{ number_format($counts['rooms']) }} rooms</strong> Room inventory available for conflict-aware planning.</div>
          </div>
        </article>

        <article class="sequence-item">
          <div class="sequence-head">
            <div class="flex gap-4">
              <div class="sequence-index">3</div>
              <div>
                <div class="sequence-label">Execute and publish</div>
                <h2 class="sequence-title">Build and publish the schedule.</h2>
                <p class="sequence-copy">Open the active term schedule.</p>
              </div>
            </div>
            <div class="actions">
              <a class="btn" href="{{ route('aop.schedule.home') }}">Open Schedule</a>
              <a class="btn secondary sm" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
            </div>
          </div>
          <div class="sequence-strip">
            <div class="sequence-chip"><strong>{{ number_format($counts['sections']) }} sections</strong> Existing records that feed scheduling work.</div>
            <div class="sequence-chip"><strong>{{ $activeTerm ? 'Ready to move' : 'Blocked by context' }}</strong> Set the term before scheduling.</div>
            <div class="sequence-chip"><strong>Readiness</strong> Review blockers before publish.</div>
          </div>
        </article>
      </div>

      <aside class="lg:col-span-5 watchlist">
        <div class="briefing-kicker">Totals</div>
        <h2 class="watchlist-title">Records on file</h2>
        <p class="watchlist-copy">Terms, courses, instructors, rooms, and sections.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Terms tracked</div>
              <div class="watchlist-note">Planning history and future cycles</div>
            </div>
            <span class="watchlist-value good">{{ number_format($counts['terms']) }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Catalog coverage</div>
              <div class="watchlist-note">Breadth of available course inputs</div>
            </div>
            <span class="watchlist-value good">{{ number_format($counts['catalog_courses']) }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Teaching roster</div>
              <div class="watchlist-note">Faculty and adjunct availability</div>
            </div>
            <span class="watchlist-value good">{{ number_format($counts['instructors']) }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Room inventory</div>
              <div class="watchlist-note">Scheduling capacity and constraints</div>
            </div>
            <span class="watchlist-value good">{{ number_format($counts['rooms']) }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Sections on record</div>
              <div class="watchlist-note">Current volume of scheduled entities</div>
            </div>
            <span class="watchlist-value good">{{ number_format($counts['sections']) }}</span>
          </div>
        </div>
      </aside>
    </section>
  </div>
</x-aop-layout>
