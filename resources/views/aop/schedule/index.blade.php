<x-aop-layout :activeTermLabel="($term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected')">
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule</h1>

      @if($term)
        <p style="margin-top:6px;">
          Active term: <strong>{{ $term->code }}</strong> — {{ $term->name }}
          @if($latestPublication)
            <span style="margin-left:10px;" class="badge">Published v{{ $latestPublication->version }}</span>
          @endif
          @if(!empty($term->schedule_locked))
            <span style="margin-left:10px;" class="badge" style="background:#fef9c3; color:#854d0e;">Locked</span>
          @endif
        </p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>

    <div class="actions">
      <a class="btn secondary" href="{{ route('dashboard') }}">Home</a>
      <a class="btn" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
      <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
    </div>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before scheduling.</p>
      <div class="actions" style="margin-top:10px;">
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      </div>
    </div>
  @else
    <div class="card" style="margin-bottom:14px;">
      <h2>Workflow Snapshot</h2>
      <div class="split" style="margin-top:10px; gap:16px;">
        <div>
          <div class="muted">Offerings</div>
          <div style="font-size:22px; font-weight:700;">{{ $summary['offerings_count'] }}</div>
        </div>
        <div>
          <div class="muted">Sections</div>
          <div style="font-size:22px; font-weight:700;">{{ $summary['sections_count'] }}</div>
        </div>
        <div>
          <div class="muted">Latest Publication</div>
          <div style="font-size:22px; font-weight:700;">{{ $summary['latest_publication_version'] ? 'v'.$summary['latest_publication_version'] : '—' }}</div>
        </div>
        <div>
          <div class="muted">Term Status</div>
          <div style="font-size:18px; font-weight:700; text-transform:capitalize;">{{ str_replace('_', ' ', $summary['term_status']) }}</div>
        </div>
        <div>
          <div class="muted">Schedule State</div>
          <span class="badge" style="{{ $summary['schedule_locked'] ? 'background:#fef9c3; color:#854d0e;' : 'background:#dcfce7; color:#166534;' }}">
            {{ $summary['schedule_locked'] ? 'Locked' : 'Unlocked' }}
          </span>
        </div>
      </div>
    </div>

    <div class="grid">
      <div class="card col-6">
        <h2>Build</h2>
        <p class="muted">Create offerings, sections, and meeting blocks for the active term.</p>
        <ul style="margin:8px 0 0 18px;">
          <li>{{ $summary['offerings_count'] }} offerings</li>
          <li>{{ $summary['sections_count'] }} sections</li>
          <li>{{ $summary['sections_missing_instructor_count'] }} sections missing instructor</li>
          <li>{{ $summary['sections_missing_meeting_blocks_count'] }} sections missing meeting blocks</li>
          <li>{{ $summary['meeting_blocks_missing_room_count'] }} meeting blocks missing room</li>
        </ul>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
          <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        </div>
      </div>

      <div class="card col-6">
        <h2>Validate</h2>
        <p class="muted">Run readiness checks and resolve timing/room conflicts before publish.</p>
        <ul style="margin:8px 0 0 18px;">
          <li>{{ $summary['room_conflict_count'] }} room conflicts</li>
          <li>{{ $summary['instructor_conflict_count'] }} instructor conflicts</li>
          <li>{{ $summary['office_hours_failing_count'] }} instructors failing office-hours requirements</li>
        </ul>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
          <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
          <a class="btn" href="{{ route('aop.schedule.calendar.index') }}">Calendar View</a>
          <a class="btn secondary" href="{{ route('aop.rooms.index') }}">Rooms</a>
        </div>
      </div>

      <div class="card col-6">
        <h2>Publish</h2>
        <p class="muted">Snapshot and distribute schedule versions when the term is ready.</p>
        <ul style="margin:8px 0 0 18px;">
          <li>Latest publication: {{ $summary['latest_publication_version'] ? 'v'.$summary['latest_publication_version'] : 'none yet' }}</li>
          <li>Term status: {{ str_replace('_', ' ', $summary['term_status']) }}</li>
          <li>Schedule: {{ $summary['schedule_locked'] ? 'Locked' : 'Unlocked' }}</li>
        </ul>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
          <a class="btn secondary" href="{{ route('aop.schedule.reports.index') }}">Reports</a>
        </div>
      </div>

      <div class="card col-6">
        <h2>Related Tools</h2>
        <p class="muted">Supporting workflows used throughout build, validation, and publication.</p>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
          <a class="btn" href="{{ route('aop.syllabi.index') }}">Open Syllabi</a>
        </div>
      </div>
    </div>
  @endif
</x-aop-layout>
