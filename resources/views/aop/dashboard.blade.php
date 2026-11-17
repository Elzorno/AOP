<x-aop-layout :activeTermLabel="$workingTerm ? 'Working term: '.$workingTerm->code.' — '.$workingTerm->name : 'No working term selected'">
  <x-slot:title>Academic Operations</x-slot:title>

  @php
    $setupReady = $counts['catalog_courses'] > 0 && $counts['instructors'] > 0 && $counts['rooms'] > 0;
    $needsAttention = ! $workingTerm || ! $setupReady || $workingCounts['unassigned_sections'] > 0;
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Academic operations</div>
        <h1 class="briefing-title">{{ $workingTerm ? $workingTerm->code.' schedule' : 'Choose a working term' }}</h1>
        <p class="briefing-copy">
          {{ $workingTerm
              ? 'Continue building, checking, and exporting '.$workingTerm->name.'.'
              : 'Open a term to begin scheduling. This changes only your workspace.' }}
        </p>

        @if ($workingTerm)
          <div class="status-ribbon">
            <span class="status-ribbon-item"><span class="status-ribbon-dot bg-blue-500"></span>{{ $workingCounts['offerings'] }} {{ $workingCounts['offerings'] === 1 ? 'course' : 'courses' }}</span>
            <span class="status-ribbon-item"><span class="status-ribbon-dot bg-blue-500"></span>{{ $workingCounts['sections'] }} {{ $workingCounts['sections'] === 1 ? 'section' : 'sections' }}</span>
            <span class="status-ribbon-item"><span class="status-ribbon-dot {{ $workingCounts['unassigned_sections'] ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>{{ $workingCounts['unassigned_sections'] ? $workingCounts['unassigned_sections'].' need faculty' : 'Faculty assignments complete' }}</span>
          </div>
        @endif

        <div class="mt-8 quick-actions">
          @if ($workingTerm)
            <a class="btn" href="{{ route('aop.schedule.home', $workingTerm) }}">Continue Scheduling</a>
            <a class="btn secondary" href="{{ route('aop.schedule.readiness.index', $workingTerm) }}">Run Schedule Check</a>
          @else
            <a class="btn" href="{{ route('aop.terms.index') }}">Choose a Term</a>
          @endif
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Attention</div>
        @if (! $needsAttention)
          <h2 class="watchlist-title">Ready to continue</h2>
          <p class="watchlist-copy">Core records and faculty assignments are in place.</p>
          <div class="mt-5"><span class="badge success">No setup blockers</span></div>
        @else
          <h2 class="watchlist-title">A few items need review</h2>
          <p class="watchlist-copy">Resolve these before the final schedule check.</p>
          <div class="watchlist-group">
            @if (! $workingTerm)
              <a class="queue-link" href="{{ route('aop.terms.index') }}"><div><div class="queue-label">Working term</div><div class="queue-copy">Open the term you want to schedule.</div></div><span class="badge warn">Choose</span></a>
            @endif
            @if (! $setupReady)
              <a class="queue-link" href="{{ route('aop.catalog.index') }}"><div><div class="queue-label">Source records</div><div class="queue-copy">Review courses, faculty, and rooms.</div></div><span class="badge warn">Review</span></a>
            @endif
            @if ($workingTerm && $workingCounts['unassigned_sections'] > 0)
              <a class="queue-link" href="{{ route('aop.schedule.sections.index', ['term' => $workingTerm, 'missing' => 'instructor']) }}"><div><div class="queue-label">Faculty assignments</div><div class="queue-copy">{{ $workingCounts['unassigned_sections'] }} {{ $workingCounts['unassigned_sections'] === 1 ? 'section needs' : 'sections need' }} faculty.</div></div><span class="badge warn">Fix</span></a>
            @endif
          </div>
        @endif
      </aside>
    </section>

    @if ($workingTerm)
      <section>
        <div class="briefing-kicker">Common tasks</div>
        <h2 class="section-heading mt-2">Everything essential, one click away</h2>
        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <a class="dock-item" href="{{ route('aop.schedule.sections.index', $workingTerm) }}"><div class="dock-kicker">Build</div><h3 class="dock-title">Courses &amp; Sections</h3><p class="dock-copy">Create sections, assign faculty, and set meeting times.</p><div class="dock-meta">Open sections</div></a>
          <a class="dock-item" href="{{ route('aop.schedule.calendar.index', $workingTerm) }}"><div class="dock-kicker">See the week</div><h3 class="dock-title">Calendar</h3><p class="dock-copy">Review class, office-hour, and open-lab placement.</p><div class="dock-meta">Open calendar</div></a>
          <a class="dock-item" href="{{ route('aop.schedule.officeHours.index', $workingTerm) }}"><div class="dock-kicker">Faculty</div><h3 class="dock-title">Faculty &amp; Office Hours</h3><p class="dock-copy">Review preferences and weekly student availability.</p><div class="dock-meta">Open faculty hours</div></a>
          <a class="dock-item" href="{{ route('aop.schedule.openLabs.index', $workingTerm) }}"><div class="dock-kicker">Lab operations</div><h3 class="dock-title">Open Labs</h3><p class="dock-copy">Schedule rooms and lab staff for open access.</p><div class="dock-meta">Open labs</div></a>
          <a class="dock-item" href="{{ route('aop.schedule.readiness.index', $workingTerm) }}"><div class="dock-kicker">Verify</div><h3 class="dock-title">Schedule Check</h3><p class="dock-copy">Find missing information, conflicts, and policy issues.</p><div class="dock-meta">Review issues</div></a>
          <a class="dock-item" href="{{ route('aop.schedule.reports.index', $workingTerm) }}"><div class="dock-kicker">Finish</div><h3 class="dock-title">Export</h3><p class="dock-copy">Download registrar, faculty, and room schedules.</p><div class="dock-meta">Open exports</div></a>
        </div>
      </section>
    @endif

    <section class="workspace-card">
      <div class="workspace-header">
        <div><div class="briefing-kicker">Configuration</div><h2 class="workspace-title">People, places, and settings</h2><p class="workspace-copy">These records change less often than the schedule.</p></div>
      </div>
      <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <a class="surface-note" href="{{ route('aop.instructors.index') }}"><strong class="text-slate-900">Faculty</strong><span class="mt-1 block">{{ $counts['instructors'] }} records</span></a>
        <a class="surface-note" href="{{ route('aop.rooms.index') }}"><strong class="text-slate-900">Rooms</strong><span class="mt-1 block">{{ $counts['rooms'] }} records</span></a>
        <a class="surface-note" href="{{ route('aop.catalog.index') }}"><strong class="text-slate-900">Course Catalog</strong><span class="mt-1 block">{{ $counts['catalog_courses'] }} records</span></a>
        <a class="surface-note" href="{{ route('aop.labtechs.index') }}"><strong class="text-slate-900">Lab Staff</strong><span class="mt-1 block">Manage lab staffing</span></a>
        <a class="surface-note" href="{{ route('aop.terms.index') }}"><strong class="text-slate-900">Terms</strong><span class="mt-1 block">{{ $counts['terms'] }} planning cycles</span></a>
        <a class="surface-note" href="{{ route('aop.accounts.index') }}"><strong class="text-slate-900">Account Links</strong><span class="mt-1 block">Connect faculty sign-ins</span></a>
      </div>
    </section>
  </div>
</x-aop-layout>
