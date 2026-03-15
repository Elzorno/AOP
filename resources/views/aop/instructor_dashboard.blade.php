<x-aop-layout :activeTermLabel="($activeTerm ? 'Active Term: '.$activeTerm->code.' — '.$activeTerm->name : 'No active term set')">
  <x-slot:title>Instructor Dashboard</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Instructor Dashboard - {{ $instructor->name }}</h1>
  </div>

  @if(session('status'))
    <div class="card panel-success">
      <strong>{{ session('status') }}</strong>
    </div>
    <div class="stack-sm"></div>
  @endif

  @if(!$activeTerm)
    <div class="card">
      <p>No active term is currently set by the administration.</p>
    </div>
  @else
    <div class="grid">
      
      <div class="card col-12">
        <h2>Your Assigned Sections</h2>
        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th>Course</th>
              <th>Section</th>
              <th>Modality</th>
              <th>Meeting Times</th>
              <th>Syllabus</th>
            </tr>
          </thead>
          <tbody>
            @forelse($sections as $sec)
              <tr>
                <td>{{ $sec->offering->catalogCourse->code }}</td>
                <td>{{ $sec->section_code }}</td>
                <td>{{ $sec->modality->value }}</td>
                <td>
                  @foreach($sec->meetingBlocks as $mb)
                    <div>
                      {{ $mb->type->value }} - {{ implode(',', $mb->days_json ?? []) }} 
                      {{ substr($mb->starts_at, 0, 5) }}-{{ substr($mb->ends_at, 0, 5) }}
                      ({{ $mb->room?->name ?? 'TBD' }})
                    </div>
                  @endforeach
                </td>
                <td>
                  @if($sec->syllabus)
                    <span class="badge success">Generated</span>
                    <a href="{{ route('aop.syllabi.show', $sec) }}" class="text-muted-xs" style="margin-left:8px;">View</a>
                  @else
                    <span class="text-muted-xs">Not Started</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5">No sections assigned to you for this term yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="card col-6">
        <h2>Teaching Preferences</h2>
        <p class="muted">Submit your preferred days and unavailable times for scheduling.</p>
        
        <form method="POST" action="{{ route('aop.instructor_preferences.store') }}">
          @csrf
          <input type="hidden" name="term_id" value="{{ $activeTerm->id }}">
          
          <label>Max Courses</label>
          <input type="number" name="max_courses" value="{{ old('max_courses', $preferences->max_courses ?? 3) }}" required>

          <label>Preferred Days (e.g. Mon,Wed,Fri)</label>
          <input type="text" name="preferred_days" value="{{ old('preferred_days', implode(',', $preferences->preferred_days ?? [])) }}">

          <label>Unavailable Times (JSON or short text list)</label>
          <textarea name="unavailable_times_raw" placeholder="e.g. Tue 8:00-10:00">{{ old('unavailable_times_raw', is_array($preferences->unavailable_times ?? null) ? implode("\n", $preferences->unavailable_times) : '') }}</textarea>

          <label>Notes</label>
          <textarea name="notes">{{ old('notes', $preferences->notes ?? '') }}</textarea>

          <div style="height:10px;"></div>
          <button type="submit" class="btn">Save Preferences</button>
        </form>
      </div>

      <div class="card col-6">
        <h2>Office Hours</h2>
        <p class="muted">Define your office hours for this term. Saving requires exact start and end times.</p>
        
        <form method="POST" action="{{ route('instructor.officeHours.store', $instructor) }}">
          @csrf
          <input type="hidden" name="from_instructor_portal" value="1">
          <label>Days</label>
          <div class="split">
            @foreach(['Mon','Tue','Wed','Thu','Fri'] as $d)
              <label style="display:flex; gap:8px; align-items:center; margin:6px 0;">
                <input type="checkbox" name="days[]" value="{{ $d }}" style="width:auto;" />
                <span>{{ $d }}</span>
              </label>
            @endforeach
          </div>

          <div class="split">
            <div>
              <label>Start</label>
              <input type="time" name="starts_at" required />
            </div>
            <div>
              <label>End</label>
              <input type="time" name="ends_at" required />
            </div>
          </div>

          <label>Location / Details</label>
          <textarea name="notes" placeholder="e.g. Room 204 or Zoom Link"></textarea>

          <div style="height:10px;"></div>
          <button type="submit" class="btn">Add Office Hours</button>
        </form>

        <table style="margin-top:10px;">
          <thead>
            <tr>
              <th>Days</th>
              <th>Time</th>
              <th>Details</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach($officeHours as $oh)
              <tr>
                <td>{{ implode(',', $oh->days_json ?? []) }}</td>
                <td>{{ substr($oh->starts_at, 0, 5) }}-{{ substr($oh->ends_at, 0, 5) }}</td>
                <td>{{ $oh->notes ?? '—' }}</td>
                <td>
                  <form method="POST" action="{{ route('instructor.officeHours.destroy', [$instructor, $oh]) }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="from_instructor_portal" value="1">
                    <button type="submit" class="btn link-danger">Remove</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

    </div>
  @endif
</x-aop-layout>
