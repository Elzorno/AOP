#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$ROOT_DIR/resources/views/aop/schedule"

cat > "$ROOT_DIR/resources/views/aop/schedule/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule</h1>
      @if($activeTerm)
        <p style="margin-top:6px;">
          Active term: <strong>{{ $activeTerm->code }}</strong> — {{ $activeTerm->name }}
          @if(!empty($publishedBadge))
            <span style="margin-left:10px;">{!! $publishedBadge !!}</span>
          @endif
          @if(!empty($lockBadge))
            <span style="margin-left:10px;">{!! $lockBadge !!}</span>
          @endif
        </p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>

    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.home') }}">Home</a>
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

  <div class="grid">
    <div class="card">
      <h2>Build</h2>
      <p class="muted">Create offerings and sections for the active term.</p>
      <div class="actions">
        <a class="btn" href="{{ route('aop.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.sections.index') }}">Sections</a>
      </div>
    </div>

    <div class="card">
      <h2>Meeting Blocks</h2>
      <p class="muted">Add days, times, rooms, and meeting types for each section.</p>
      <div class="actions">
        <a class="btn" href="{{ route('aop.meetingBlocks.index') }}">Meeting Blocks</a>
        <a class="btn secondary" href="{{ route('aop.rooms.index') }}">Rooms</a>
      </div>
    </div>

    <div class="card">
      <h2>Office Hours</h2>
      <p class="muted">Office hours per instructor for the active term. Lock per instructor when done.</p>
      <div class="actions">
        <a class="btn" href="{{ route('aop.officeHours.index') }}">Office Hours</a>
      </div>
    </div>

    <div class="card">
      <h2>Syllabi</h2>
      <p class="muted">Generate DOCX/PDF syllabi using the template and published/locked schedule data.</p>
      <div class="actions">
        <a class="btn" href="{{ route('aop.syllabi.index') }}">Open Syllabi</a>
      </div>
    </div>
  </div>
</x-aop-layout>
BLADE

chown www-data:www-data "$ROOT_DIR/resources/views/aop/schedule/index.blade.php"
chmod 644 "$ROOT_DIR/resources/views/aop/schedule/index.blade.php"

echo "OK: Added Syllabi link to Schedule hub."
