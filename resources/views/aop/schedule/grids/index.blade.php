<x-aop-layout>
  <x-slot:title>Schedule Grids</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Grids</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        <p class="muted">Instructor grid includes office hours. Room grid excludes office hours. Use Print for a printer-friendly layout.</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before viewing schedule grids.</p>
    </div>
  @else
    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <div class="card">
        <h2>Instructor Grid</h2>
        <p class="muted">Classes + office hours</p>
        <form method="get" action="" onsubmit="return false;">
          <label class="label">Instructor</label>
          <div class="row" style="gap:10px; align-items:flex-end;">
            <select class="input" id="instructorSelect">
              <option value="">Select...</option>
              @foreach($instructors as $ins)
                <option value="{{ $ins->id }}">{{ $ins->name }}</option>
              @endforeach
            </select>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('instructorSelect').value;
              if(!id){ alert('Select an instructor.'); return; }
              window.location='{{ url('/aop/schedule/grids/instructors') }}/'+id;
            ">View</button>
          </div>
        </form>
      </div>

      <div class="card">
        <h2>Room Grid</h2>
        <p class="muted">Classes only</p>
        <form method="get" action="" onsubmit="return false;">
          <label class="label">Room</label>
          <div class="row" style="gap:10px; align-items:flex-end;">
            <select class="input" id="roomSelect">
              <option value="">Select...</option>
              @foreach($rooms as $r)
                <option value="{{ $r->id }}">{{ $r->name }}</option>
              @endforeach
            </select>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('roomSelect').value;
              if(!id){ alert('Select a room.'); return; }
              window.location='{{ url('/aop/schedule/grids/rooms') }}/'+id;
            ">View</button>
          </div>
        </form>
      </div>
    </div>
  @endif
</x-aop-layout>
