<x-aop-layout>
  <x-slot:title>Schedule Grids</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Grids</h1>
      <p style="margin-top:6px;">Instructor grid includes office hours. Room grid excludes office hours.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
    </div>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before viewing grids.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>

      <div class="grid" style="margin-top:14px;">
        <div class="col-6">
          <div class="card" style="border-radius:14px;">
            <h2>Instructor Grid</h2>
            <p>Select an instructor to view their weekly grid (classes + office hours).</p>
            <form method="GET" action="{{ route('aop.schedule.grids.index') }}" onsubmit="return false;">
              <label>Instructor</label>
              <select id="instructor_select">
                <option value="">-- Select --</option>
                @foreach ($instructors as $i)
                  <option value="{{ $i->id }}">{{ $i->name }}</option>
                @endforeach
              </select>
              <div class="actions" style="margin-top:10px;">
                <a class="btn" href="#" onclick="var id=document.getElementById('instructor_select').value; if(!id){alert('Select an instructor.'); return false;} window.location='{{ url('/aop/schedule/grids/instructors') }}/'+id; return false;">View Instructor Grid</a>
              </div>
            </form>
          </div>
        </div>

        <div class="col-6">
          <div class="card" style="border-radius:14px;">
            <h2>Room Grid</h2>
            <p>Select a room to view its weekly grid (classes only).</p>
            <form method="GET" action="{{ route('aop.schedule.grids.index') }}" onsubmit="return false;">
              <label>Room</label>
              <select id="room_select">
                <option value="">-- Select --</option>
                @foreach ($rooms as $r)
                  <option value="{{ $r->id }}">{{ $r->name }}</option>
                @endforeach
              </select>
              <div class="actions" style="margin-top:10px;">
                <a class="btn" href="#" onclick="var id=document.getElementById('room_select').value; if(!id){alert('Select a room.'); return false;} window.location='{{ url('/aop/schedule/grids/rooms') }}/'+id; return false;">View Room Grid</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    @endif
  </div>
</x-aop-layout>
