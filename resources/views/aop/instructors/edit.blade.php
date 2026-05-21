<x-aop-layout>
  <x-slot:title>Edit Instructor</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Instructor</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.instructors.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.instructors.update', $instructor) }}">
      @csrf
      @method('PUT')

      <label>Name</label>
      <input name="name" required value="{{ old('name', $instructor->name) }}" />

      <label>Email</label>
      <input name="email" type="email" value="{{ old('email', $instructor->email) }}" />

      <div class="split">
        <div>
          <label>Full-time</label>
          <select name="is_full_time">
            <option value="0" {{ !$instructor->is_full_time ? 'selected' : '' }}>No</option>
            <option value="1" {{ $instructor->is_full_time ? 'selected' : '' }}>Yes</option>
          </select>
        </div>
        <div>
          <label>Color (hex)</label>
          <input name="color_hex" placeholder="#3b82f6" value="{{ old('color_hex', $instructor->color_hex) }}" />
        </div>
        <div>
          <label>Active</label>
          <select name="is_active">
            <option value="1" {{ $instructor->is_active ? 'selected' : '' }}>Yes</option>
            <option value="0" {{ !$instructor->is_active ? 'selected' : '' }}>No</option>
          </select>
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Save Changes</button>
    </form>
  </div>

  @php
    $activeTerm = \App\Models\Term::where('is_active', true)->first();
    $preferences = $activeTerm
        ? \App\Models\InstructorPreference::where('term_id', $activeTerm->id)
            ->where('instructor_id', $instructor->id)
            ->first()
        : null;
    $prefDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  @endphp

  @if ($activeTerm)
    <div class="card" style="margin-top:16px;">
      <details class="disclosure" {{ $errors->has('max_courses') || $errors->has('preferred_days') || $errors->has('notes') ? 'open' : '' }}>
        <summary class="disclosure-summary">Scheduling preferences ({{ $activeTerm->code }})</summary>
        <div class="disclosure-body">
          <p class="table-note" style="margin-bottom:12px;">
            Preferred days rank auto-suggestions higher. Unavailable times are excluded from suggestions.
            Max courses flags overloaded instructors.
          </p>

          <form method="POST" action="{{ route('aop.instructors.preferences.upsert', $instructor) }}">
            @csrf

            <div class="inline-form-grid" style="align-items:start;">
              <div>
                <label>Max courses this term</label>
                <input type="number" name="max_courses" min="0" max="20"
                  value="{{ old('max_courses', $preferences?->max_courses ?? 3) }}" />
              </div>
              <div>
                <label>Notes</label>
                <input type="text" name="notes" placeholder="Optional scheduling notes"
                  value="{{ old('notes', $preferences?->notes) }}" />
              </div>
            </div>

            <label style="margin-top:12px;display:block;">Preferred days</label>
            <div class="inline-form-grid-3" style="margin-top:6px;">
              @foreach ($prefDays as $day)
                <label class="checkbox-row">
                  <input type="checkbox" name="preferred_days[]" value="{{ $day }}"
                    {{ in_array($day, old('preferred_days', $preferences?->preferred_days ?? []), true) ? 'checked' : '' }} />
                  <span>{{ $day }}</span>
                </label>
              @endforeach
            </div>

            <div class="section-actions" style="margin-top:16px;">
              <button class="btn" type="submit">Save Preferences</button>
              <a class="btn secondary" href="{{ route('aop.schedule.officeHours.show', $instructor) }}">Full preferences &amp; office hours</a>
            </div>
          </form>
        </div>
      </details>
    </div>
  @endif
</x-aop-layout>
