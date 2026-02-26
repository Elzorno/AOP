<x-aop-layout>
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Schedule</h1>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before scheduling.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>

      <div style="margin-top:8px;">
        @if($term->schedule_locked)
          <p class="muted">Schedule lock: <span class="badge">Locked</span>
            @if($term->schedule_locked_at)
              {{ $term->schedule_locked_at->format('Y-m-d H:i') }}
            @endif
            @if($term->scheduleLockedBy)
              by {{ $term->scheduleLockedBy->name }}
            @endif
          </p>
        @else
          <p class="muted">Schedule lock: <span class="badge">Unlocked</span></p>
        @endif

        <div class="actions" style="margin-top:8px; flex-wrap:wrap; gap:8px;">
          @if($term->schedule_locked)
            <form method="POST" action="{{ route('aop.schedule.term.unlock') }}" style="display:inline;">
              @csrf
              <button class="btn secondary" type="submit">Unlock Schedule</button>
            </form>
          @else
            <form method="POST" action="{{ route('aop.schedule.term.lock') }}" style="display:inline;">
              @csrf
              <button class="btn" type="submit">Lock Schedule</button>
            </form>
          @endif

          <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness Dashboard</a>
        </div>
      </div>

      @if($latestPublication)
        <p class="muted" style="margin-top:10px;">Published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
      @else
        <p class="muted" style="margin-top:10px;">Published: <span class="badge">None</span></p>
      @endif

      <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
        <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
      </div>

      @if($term->schedule_locked)
        <p class="muted" style="margin-top:10px;">Note: schedule edits (sections, meeting blocks, office hours) are disabled while locked.</p>
      @endif
    @endif
  </div>
</x-aop-layout>
