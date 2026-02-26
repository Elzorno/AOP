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
      <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
      <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Reports</a>
      <a class="btn" href="{{ route('aop.syllabi.index') }}">Syllabi</a>
      <a class="btn secondary" href="{{ route('aop.schedule.publish.index') }}">Publish</a>
      <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
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
    <div class="grid">
      <div class="card col-6">
        <h2>Build</h2>
        <p class="muted">Create offerings and sections for the active term.</p>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
          <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        </div>
      </div>

      <div class="card col-6">
        <h2>Meeting Blocks</h2>
        <p class="muted">Add days, times, rooms, and meeting types for each section.</p>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Edit Meeting Blocks</a>
          <a class="btn secondary" href="{{ route('aop.rooms.index') }}">Rooms</a>
        </div>
      </div>

      <div class="card col-6">
        <h2>Office Hours</h2>
        <p class="muted">Office hours per instructor for the active term. Lock per instructor when done.</p>
        <div class="actions">
          <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        </div>
      </div>

      <div class="card col-6">
        <h2>Syllabi</h2>
        <p class="muted">Generate DOCX/PDF syllabi using the template and published/locked schedule data.</p>
        <div class="actions">
          <a class="btn" href="{{ route('aop.syllabi.index') }}">Open Syllabi</a>
        </div>
      </div>
    </div>
  @endif
</x-aop-layout>
