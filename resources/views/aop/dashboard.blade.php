<x-aop-layout :activeTermLabel="$activeTerm ? 'Active Term: '.$activeTerm->code.' - '.$activeTerm->name : 'No active term selected'">
  <x-slot:title>Dashboard</x-slot:title>

  @php
    $isReadyToSchedule = $activeTerm && $counts['catalog_courses'] > 0 && $counts['instructors'] > 0 && $counts['rooms'] > 0;
    $nextActionLabel = $activeTerm ? 'Open schedule workspace' : 'Set the active term';
    $nextActionRoute = $activeTerm ? route('aop.schedule.home') : route('aop.terms.index');
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Operations briefing</div>
        <h1 class="briefing-title">{{ $activeTerm ? 'Keep the active term moving with one clear path.' : 'Start by selecting the term everyone should work inside.' }}</h1>
        <p class="briefing-copy">
          {{ $activeTerm
              ? 'Check term status, confirm setup, and continue directly into schedule work.'
              : 'Choose an active term, then continue into setup and schedule work.' }}
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
        <div class="briefing-kicker">Control panel</div>
        <h2 class="watchlist-title">Current status</h2>
        <p class="watchlist-copy">Current term, next step, and setup status.</p>

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
              <div class="watchlist-note">Shortest route to forward progress</div>
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
        <h2 class="dock-title">Terms stay first</h2>
        <p class="dock-copy">One location for activation, cloning, and lifecycle changes keeps the schedule context consistent across the product.</p>
        <div class="dock-meta">Open terms</div>
      </a>

      <a href="{{ route('aop.catalog.index') }}" class="dock-item">
        <div class="dock-kicker">Inputs</div>
        <h2 class="dock-title">Check the planning ingredients</h2>
        <p class="dock-copy">Review catalog, instructor, and room data before creating sections.</p>
        <div class="dock-meta">Review source data</div>
      </a>

      <a href="{{ route('aop.schedule.home') }}" class="dock-item">
        <div class="dock-kicker">Execution</div>
        <h2 class="dock-title">Operate from one workspace</h2>
        <p class="dock-copy">Scheduling, validation, and publishing stay together in one workspace.</p>
        <div class="dock-meta">Launch schedule</div>
      </a>

      <a href="{{ route('aop.syllabi.index') }}" class="dock-item">
        <div class="dock-kicker">Downstream</div>
        <h2 class="dock-title">Keep supporting work nearby</h2>
        <p class="dock-copy">Open syllabus management, templates, and section exports from one place.</p>
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
                <h2 class="sequence-title">Choose the term everyone should be working in.</h2>
                <p class="sequence-copy">The active term becomes the default context for schedule and syllabus work.</p>
              </div>
            </div>
            <div class="actions">
              <a class="btn secondary sm" href="{{ route('aop.terms.index') }}">Terms</a>
              <a class="btn secondary sm" href="{{ route('aop.terms.create') }}">Create Term</a>
            </div>
          </div>
          <div class="sequence-strip">
            <div class="sequence-chip"><strong>{{ number_format($counts['terms']) }} terms</strong> Available for planning, cloning, and activation.</div>
            <div class="sequence-chip"><strong>{{ $activeTerm ? $activeTerm->code : 'No active term' }}</strong> Current context seen across schedule tools.</div>
            <div class="sequence-chip"><strong>{{ $activeTerm ? 'Aligned' : 'Needs action' }}</strong> Schedule tools use this context automatically.</div>
          </div>
        </article>

        <article class="sequence-item">
          <div class="sequence-head">
            <div class="flex gap-4">
              <div class="sequence-index">2</div>
              <div>
                <div class="sequence-label">Prepare inputs</div>
                <h2 class="sequence-title">Verify the data that drives assignments and conflicts.</h2>
                <p class="sequence-copy">Review source data before building sections and room assignments.</p>
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
                <h2 class="sequence-title">Operate the schedule from one place instead of chasing tools.</h2>
                <p class="sequence-copy">Build, validate, and publish from the schedule workspace.</p>
              </div>
            </div>
            <div class="actions">
              <a class="btn" href="{{ route('aop.schedule.home') }}">Open Schedule</a>
              <a class="btn secondary sm" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
            </div>
          </div>
          <div class="sequence-strip">
            <div class="sequence-chip"><strong>{{ number_format($counts['sections']) }} sections</strong> Existing records that feed scheduling work.</div>
            <div class="sequence-chip"><strong>{{ $activeTerm ? 'Ready to move' : 'Blocked by context' }}</strong> Progress is fastest when the active term is already selected.</div>
            <div class="sequence-chip"><strong>One launch point</strong> Reduces clicks by keeping the full schedule workflow together.</div>
          </div>
        </article>
      </div>

      <aside class="lg:col-span-5 watchlist">
        <div class="briefing-kicker">Operational signals</div>
        <h2 class="watchlist-title">Workspace totals</h2>
        <p class="watchlist-copy">Workspace counts across planning, staffing, rooms, and sections.</p>

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
