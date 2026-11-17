<x-aop-layout :activeTermLabel="'Working term: '.$term->code.' - '.$term->name">
  <x-slot:title>New Open Lab</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>New Open Lab</h1>
      <p class="muted">Open labs require a room, a lab tech, and a schedule that does not collide with class use.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.openLabs.index', $term) }}">Back</a>
    </div>
  </div>

  @if($scheduleLocked)
    <div class="card panel-warn" style="margin-bottom:14px;">
      <strong>The active term is locked.</strong>
      <div class="muted" style="margin-top:6px;">Unlock the schedule before creating open labs.</div>
    </div>
  @endif

  <div class="card">
    @if($rooms->isEmpty() || $labTechs->isEmpty())
      <h2>Setup required</h2>
      <p class="muted">Add at least one active room and one active lab tech before scheduling open labs.</p>
      <div class="actions" style="margin-top:10px;">
        <a class="btn secondary" href="{{ route('aop.rooms.index') }}">Rooms</a>
        <a class="btn secondary" href="{{ route('aop.labtechs.index') }}">Lab Techs</a>
      </div>
    @else
      <form method="POST" action="{{ route('aop.schedule.openLabs.store', $term) }}">
        @csrf

        <label>Name</label>
        <input name="name" required value="{{ old('name') }}" placeholder="ATC 228 Open Lab" />

        <div class="split">
          <div>
            <label>Room</label>
            <select name="room_id" required>
              <option value="">Select...</option>
              @foreach($rooms as $room)
                <option value="{{ $room->id }}" {{ (string) old('room_id') === (string) $room->id ? 'selected' : '' }}>
                  {{ $room->name }}
                </option>
              @endforeach
            </select>
          </div>
          <div>
            <label>Lab Tech</label>
            <select name="lab_tech_id" required>
              <option value="">Select...</option>
              @foreach($labTechs as $labTech)
                <option value="{{ $labTech->id }}" {{ (string) old('lab_tech_id') === (string) $labTech->id ? 'selected' : '' }}>
                  {{ $labTech->name }} · {{ number_format((float) $labTech->weekly_hour_limit, 2) }} hrs
                </option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="split">
          <div>
            <label>Start</label>
            <input type="time" name="starts_at" required value="{{ old('starts_at') }}" />
          </div>
          <div>
            <label>End</label>
            <input type="time" name="ends_at" required value="{{ old('ends_at') }}" />
          </div>
        </div>

        <label>Days</label>
        <div class="inline-form-grid-3" style="margin-top:6px;">
          @foreach($weekDays as $day)
            <label class="checkbox-row">
              <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, old('days', []), true) ? 'checked' : '' }} />
              <span>{{ $day }}</span>
            </label>
          @endforeach
        </div>

        <label style="margin-top:12px;">Notes</label>
        <input name="notes" value="{{ old('notes') }}" placeholder="Optional notes for public posting or room coverage" />

        <div style="height:12px;"></div>
        <button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Create Open Lab</button>
      </form>
    @endif
  </div>
</x-aop-layout>
