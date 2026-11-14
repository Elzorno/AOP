<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>New Scheduled Event</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div><h1>New Scheduled Event</h1><p class="muted">Add a class meeting or staffed open lab through one scheduling flow.</p></div>
    <a class="btn secondary" href="{{ route('aop.schedule.events.index') }}">Back</a>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.schedule.events.store') }}" data-scheduled-event-form>
      @csrf
      <label>Event Type</label>
      <select name="event_type" required data-event-type>
        <option value="class" {{ old('event_type', 'class') === 'class' ? 'selected' : '' }}>Class Meeting</option>
        <option value="open_lab" {{ old('event_type') === 'open_lab' ? 'selected' : '' }}>Open Lab</option>
      </select>

      <div data-class-fields>
        <label>Section</label>
        <select name="section_id">
          <option value="">Select a section...</option>
          @foreach($sections as $section)
            <option value="{{ $section->id }}" {{ (string) old('section_id') === (string) $section->id ? 'selected' : '' }}>{{ $section->offering->catalogCourse?->code }} {{ $section->section_code }} · {{ $section->instructor_display_name ?? 'Instructor TBD' }}</option>
          @endforeach
        </select>
        <label>Meeting Type</label>
        <select name="category">
          @foreach($meetingTypes as $meetingType)
            <option value="{{ $meetingType->value }}" {{ old('category', 'LECTURE') === $meetingType->value ? 'selected' : '' }}>{{ $meetingType->value }}</option>
          @endforeach
        </select>
      </div>

      <div data-open-lab-fields hidden>
        <label>Open Lab Name</label><input name="name" value="{{ old('name') }}" placeholder="ATC 228 Open Lab" />
        <label>Lab Tech</label>
        <select name="lab_tech_id"><option value="">Select a lab tech...</option>@foreach($labTechs as $labTech)<option value="{{ $labTech->id }}" {{ (string) old('lab_tech_id') === (string) $labTech->id ? 'selected' : '' }}>{{ $labTech->name }} · {{ number_format((float) $labTech->weekly_hour_limit, 2) }} hrs</option>@endforeach</select>
      </div>

      <label>Room</label>
      <select name="room_id"><option value="">Select a room...</option>@foreach($rooms as $room)<option value="{{ $room->id }}" {{ (string) old('room_id') === (string) $room->id ? 'selected' : '' }}>{{ $room->name }}</option>@endforeach</select>
      <div class="split"><div><label>Start</label><input type="time" name="starts_at" required value="{{ old('starts_at') }}" /></div><div><label>End</label><input type="time" name="ends_at" required value="{{ old('ends_at') }}" /></div></div>
      <label>Days</label><div class="inline-form-grid-3" style="margin-top:6px;">@foreach($weekDays as $day)<label class="checkbox-row"><input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, old('days', []), true) ? 'checked' : '' }} /><span>{{ $day }}</span></label>@endforeach</div>
      <label style="margin-top:12px;">Notes</label><input name="notes" value="{{ old('notes') }}" placeholder="Optional schedule note" />
      <div style="height:12px;"></div><button class="btn" type="submit" {{ $scheduleLocked ? 'disabled' : '' }}>Create Event</button>
    </form>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.querySelector('[data-scheduled-event-form]');
      if (!form) return;
      const type = form.querySelector('[data-event-type]');
      const classFields = form.querySelector('[data-class-fields]');
      const labFields = form.querySelector('[data-open-lab-fields]');
      const sync = () => { const isLab = type.value === 'open_lab'; classFields.hidden = isLab; labFields.hidden = !isLab; };
      type.addEventListener('change', sync); sync();
    });
  </script>
</x-aop-layout>
