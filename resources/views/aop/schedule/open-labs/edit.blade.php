<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Edit Open Lab</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Edit Open Lab</h1>
      <p class="muted">{{ $openLab->name }} · {{ $openLab->room?->name ?? 'No room' }}</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.openLabs.index', $term) }}">Back</a>
    </div>
  </div>

  @if($scheduleLocked)
    <div class="card panel-warn" style="margin-bottom:14px;">
      <strong>The active term is locked.</strong>
      <div class="muted" style="margin-top:6px;">Unlock the schedule before editing or deleting this open lab.</div>
    </div>
  @endif

  <div class="card">
    <form method="POST" action="{{ route('aop.schedule.openLabs.update', [$term, $openLab]) }}">
      @csrf
      @method('PUT')

      <label>Name</label>
      <input name="name" required value="{{ old('name', $openLab->name) }}" />

      <div class="split">
        <div>
          <label>Room</label>
          <select name="room_id" required>
            <option value="">Select...</option>
            @foreach($rooms as $room)
              <option value="{{ $room->id }}" {{ (string) old('room_id', $openLab->room_id) === (string) $room->id ? 'selected' : '' }}>
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
              <option value="{{ $labTech->id }}" {{ (string) old('lab_tech_id', $openLab->lab_tech_id) === (string) $labTech->id ? 'selected' : '' }}>
                {{ $labTech->name }} · {{ number_format((float) $labTech->weekly_hour_limit, 2) }} hrs
              </option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="split">
        <div>
          <label>Start</label>
          <input type="time" name="starts_at" required value="{{ old('starts_at', substr((string) $openLab->starts_at, 0, 5)) }}" />
        </div>
        <div>
          <label>End</label>
          <input type="time" name="ends_at" required value="{{ old('ends_at', substr((string) $openLab->ends_at, 0, 5)) }}" />
        </div>
      </div>

      <label>Days</label>
      <div class="inline-form-grid-3" style="margin-top:6px;">
        @foreach($weekDays as $day)
          <label class="checkbox-row">
            <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, old('days', $openLab->days_json ?? []), true) ? 'checked' : '' }} />
            <span>{{ $day }}</span>
          </label>
        @endforeach
      </div>

      <label style="margin-top:12px;">Notes</label>
      <input name="notes" value="{{ old('notes', $openLab->notes) }}" placeholder="Optional notes for public posting or room coverage" />

      <div style="height:12px;"></div>
      <button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Save Changes</button>
    </form>
  </div>

  <div class="card" style="margin-top:16px;">
    <form method="POST" action="{{ route('aop.schedule.openLabs.destroy', [$term, $openLab]) }}" onsubmit="return confirm('Remove this open lab?');">
      @csrf
      @method('DELETE')
      <button class="btn danger" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Remove Open Lab</button>
    </form>
  </div>
</x-aop-layout>
