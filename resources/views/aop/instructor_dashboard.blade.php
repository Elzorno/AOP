<x-aop-layout :activeTermLabel="($activeTerm ? 'Current term: '.$activeTerm->code.' — '.$activeTerm->name : 'No current term')">
  <x-slot:title>My Faculty Workspace</x-slot:title>

  @php
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $dayNames = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];
    $preferredDays = old('preferred_days', $preferences?->preferred_days ?? []);
    $unavailableTimes = old('unavailable_times_raw', implode("\n", $preferences?->unavailable_times ?? []));
    $needsPreferences = $activeTerm && ! $preferences;
    $needsOfficeHours = $activeTerm && $officeHours->isEmpty();
    $needsAttention = $needsPreferences || $needsOfficeHours;
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Faculty workspace</div>
        <h1 class="briefing-title">Welcome, {{ $instructor->name }}</h1>
        <p class="briefing-copy">
          {{ $activeTerm
              ? 'Your classes, teaching preferences, and office hours for '.$activeTerm->name.' are together here.'
              : 'Your faculty workspace is ready. The department has not opened a current term yet.' }}
        </p>

        @if ($activeTerm)
          <div class="status-ribbon">
            <span class="status-ribbon-item"><span class="status-ribbon-dot bg-blue-500"></span>{{ $sections->count() }} assigned {{ $sections->count() === 1 ? 'section' : 'sections' }}</span>
            <span class="status-ribbon-item"><span class="status-ribbon-dot {{ $preferences ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>Preferences {{ $preferences ? 'saved' : 'needed' }}</span>
            <span class="status-ribbon-item"><span class="status-ribbon-dot {{ $officeHours->isNotEmpty() ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>{{ $officeHours->count() }} office-hour {{ $officeHours->count() === 1 ? 'block' : 'blocks' }}</span>
          </div>
          <div class="mt-8 quick-actions">
            <a class="btn" href="#my-schedule">View My Schedule</a>
            <a class="btn secondary" href="#preferences">Update Preferences</a>
            <a class="btn secondary" href="#office-hours">Manage Office Hours</a>
          </div>
        @endif
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Next step</div>
        @if (! $activeTerm)
          <h2 class="watchlist-title">Nothing is required yet</h2>
          <p class="watchlist-copy">The department will set the current term when scheduling begins.</p>
        @elseif ($needsAttention)
          <h2 class="watchlist-title">Your input is needed</h2>
          <p class="watchlist-copy">Complete the highlighted items so the department can schedule accurately.</p>
          <div class="watchlist-group">
            @if ($needsPreferences)
              <a class="queue-link" href="#preferences"><div><div class="queue-label">Teaching preferences</div><div class="queue-copy">Tell the scheduler when you prefer to teach.</div></div><span class="badge warn">Add</span></a>
            @endif
            @if ($needsOfficeHours)
              <a class="queue-link" href="#office-hours"><div><div class="queue-label">Office hours</div><div class="queue-copy">Add at least one weekly office-hour block.</div></div><span class="badge warn">Add</span></a>
            @endif
          </div>
        @else
          <h2 class="watchlist-title">You’re up to date</h2>
          <p class="watchlist-copy">Your teaching preferences and office hours are on file for this term.</p>
          <div class="mt-5"><span class="badge success">No action needed</span></div>
        @endif
      </aside>
    </section>

    @if ($activeTerm)
      <section id="my-schedule" class="workspace-card scroll-mt-6">
        <div class="workspace-header">
          <div><div class="briefing-kicker">My schedule</div><h2 class="workspace-title">Classes for {{ $activeTerm->code }}</h2><p class="workspace-copy">Your assigned sections, meeting times, locations, and delivery formats.</p></div>
          <button class="btn secondary" type="button" onclick="window.print()">Print My Schedule</button>
        </div>

        @forelse ($sections as $section)
          <article class="meeting-card mt-5">
            <div class="meeting-head">
              <div><div class="offering-kicker">{{ $section->offering->catalogCourse->code }} · Section {{ $section->section_code }}</div><h3 class="meeting-title">{{ $section->offering->catalogCourse->title }}</h3></div>
              <span class="badge info">{{ str_replace('_', ' ', $section->modality->value) }}</span>
            </div>
            @if ($section->meetingBlocks->isEmpty())
              <div class="surface-note mt-4">Meeting time has not been assigned yet.</div>
            @else
              <div class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($section->meetingBlocks as $block)
                  <div class="surface-note"><div class="font-semibold text-slate-900">{{ implode(', ', $block->days_json ?? []) ?: 'Days pending' }}</div><div class="mt-1">{{ substr($block->starts_at, 0, 5) }}–{{ substr($block->ends_at, 0, 5) }}</div><div class="mt-1 text-slate-500">{{ $block->room?->name ?? ($section->modality->value === 'ONLINE' ? 'Online' : 'Room pending') }}</div></div>
                @endforeach
              </div>
            @endif
          </article>
        @empty
          <div class="surface-note mt-5">No sections are assigned to you for this term yet.</div>
        @endforelse
      </section>

      <div class="editor-layout">
        <div class="editor-main">
          <section id="preferences" class="workspace-card scroll-mt-6">
            <div class="workspace-header">
              <div><div class="briefing-kicker">Teaching preferences</div><h2 class="workspace-title">When do you prefer to teach?</h2><p class="workspace-copy">These preferences guide scheduling but do not change your assigned classes directly.</p></div>
              <span class="badge {{ $preferences ? 'success' : 'warn' }}">{{ $preferences ? 'Saved' : 'Needed' }}</span>
            </div>

            <form method="POST" action="{{ route('aop.instructor_preferences.store') }}" class="mt-6">
              @csrf
              <label for="max_courses">Maximum courses this term</label>
              <select id="max_courses" name="max_courses" class="mt-2" required>
                @for ($count = 1; $count <= 8; $count++)
                  <option value="{{ $count }}" {{ (int) old('max_courses', $preferences?->max_courses ?? 3) === $count ? 'selected' : '' }}>{{ $count }}</option>
                @endfor
              </select>

              <fieldset class="mt-6">
                <legend class="font-semibold text-slate-900">Preferred teaching days</legend>
                <p class="mt-1 text-sm text-slate-500">Choose every day that works especially well for you.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  @foreach ($days as $day)
                    <label class="checkbox-row"><input type="checkbox" name="preferred_days[]" value="{{ $day }}" {{ in_array($day, $preferredDays, true) ? 'checked' : '' }}><span>{{ $dayNames[$day] }}</span></label>
                  @endforeach
                </div>
              </fieldset>

              <div class="mt-6">
                <label for="unavailable_times_raw">Times you cannot teach</label>
                <p id="unavailable_help" class="mt-1 text-sm text-slate-500">Add one time per line, such as “Tuesday 8:00 AM–10:00 AM.”</p>
                <textarea id="unavailable_times_raw" name="unavailable_times_raw" rows="4" aria-describedby="unavailable_help" class="mt-2" placeholder="Tuesday 8:00 AM–10:00 AM&#10;Friday after 2:00 PM">{{ $unavailableTimes }}</textarea>
              </div>
              <div class="mt-6"><label for="preference_notes">Anything else the scheduler should know?</label><textarea id="preference_notes" name="notes" rows="3" class="mt-2" placeholder="Optional notes">{{ old('notes', $preferences?->notes) }}</textarea></div>
              <div class="section-actions"><button class="btn" type="submit">Save Teaching Preferences</button></div>
            </form>
          </section>

          <section id="office-hours" class="workspace-card scroll-mt-6">
            <div class="workspace-header">
              <div><div class="briefing-kicker">Office hours</div><h2 class="workspace-title">Your weekly availability</h2><p class="workspace-copy">Add or update the times students can meet with you.</p></div>
              @if ($officeHoursLocked)<span class="badge muted">Locked by department</span>@endif
            </div>

            @if ($officeHoursLocked)
              <div class="surface-note mt-5">Office hours have been finalized for this term. Contact the department if a change is needed.</div>
            @else
              <div class="mt-5 space-y-4">
                @foreach ($officeHours as $block)
                  <article class="meeting-card">
                    <div class="meeting-head"><div><h3 class="meeting-title">{{ implode(', ', $block->days_json ?? []) }}</h3><p class="meeting-copy">{{ substr($block->starts_at, 0, 5) }}–{{ substr($block->ends_at, 0, 5) }}{{ $block->notes ? ' · '.$block->notes : '' }}</p></div><span class="badge success">On file</span></div>
                    <details class="disclosure mt-4">
                      <summary class="disclosure-summary">Edit this block</summary>
                      <div class="disclosure-body">
                        <form method="POST" action="{{ route('instructor.officeHours.update', [$instructor, $block]) }}">
                          @csrf @method('PUT')
                          <input type="hidden" name="from_instructor_portal" value="1">
                          <fieldset><legend class="font-semibold text-slate-900">Days</legend><div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach ($days as $day)<label class="checkbox-row"><input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $block->days_json ?? [], true) ? 'checked' : '' }}><span>{{ $day }}</span></label>@endforeach</div></fieldset>
                          <div class="inline-form-grid-2"><div><label for="start_{{ $block->id }}">Start</label><input id="start_{{ $block->id }}" type="time" name="starts_at" value="{{ substr($block->starts_at, 0, 5) }}" required></div><div><label for="end_{{ $block->id }}">End</label><input id="end_{{ $block->id }}" type="time" name="ends_at" value="{{ substr($block->ends_at, 0, 5) }}" required></div></div>
                          <label for="notes_{{ $block->id }}">Location or meeting details</label><input id="notes_{{ $block->id }}" name="notes" value="{{ $block->notes }}" placeholder="Office, library, or video link">
                          <div class="section-actions"><button class="btn" type="submit">Save Changes</button></div>
                        </form>
                        <details class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4"><summary class="cursor-pointer font-semibold text-red-800">Delete this block</summary><p class="mt-2 text-sm text-red-700">This removes the office-hour block from the current term.</p><form method="POST" action="{{ route('instructor.officeHours.destroy', [$instructor, $block]) }}" class="mt-3">@csrf @method('DELETE')<input type="hidden" name="from_instructor_portal" value="1"><button type="submit" class="btn danger">Delete Office Hours</button></form></details>
                      </div>
                    </details>
                  </article>
                @endforeach
              </div>

              <details class="disclosure mt-5" {{ $officeHours->isEmpty() ? 'open' : '' }}>
                <summary class="disclosure-summary">Add office hours</summary>
                <div class="disclosure-body">
                  <form method="POST" action="{{ route('instructor.officeHours.store', $instructor) }}">
                    @csrf <input type="hidden" name="from_instructor_portal" value="1">
                    <fieldset><legend class="font-semibold text-slate-900">Days</legend><div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach ($days as $day)<label class="checkbox-row"><input type="checkbox" name="days[]" value="{{ $day }}"><span>{{ $day }}</span></label>@endforeach</div></fieldset>
                    <div class="inline-form-grid-2"><div><label for="new_office_start">Start</label><input id="new_office_start" type="time" name="starts_at" required></div><div><label for="new_office_end">End</label><input id="new_office_end" type="time" name="ends_at" required></div></div>
                    <label for="new_office_notes">Location or meeting details</label><input id="new_office_notes" name="notes" placeholder="Office, library, or video link">
                    <div class="section-actions"><button class="btn" type="submit">Add Office Hours</button></div>
                  </form>
                </div>
              </details>
            @endif
          </section>
        </div>

        <aside class="editor-side">
          <section class="watchlist">
            <div class="briefing-kicker">Current term</div><h2 class="watchlist-title">{{ $activeTerm->code }}</h2><p class="watchlist-copy">{{ $activeTerm->name }}</p>
            <div class="watchlist-group">
              <div class="watchlist-item"><div><div class="watchlist-name">Assigned sections</div><div class="watchlist-note">Your teaching schedule</div></div><span class="watchlist-value good">{{ $sections->count() }}</span></div>
              <div class="watchlist-item"><div><div class="watchlist-name">Preferred days</div><div class="watchlist-note">Saved scheduling input</div></div><span class="watchlist-value {{ count($preferences?->preferred_days ?? []) ? 'good' : 'warn' }}">{{ count($preferences?->preferred_days ?? []) }}</span></div>
              <div class="watchlist-item"><div><div class="watchlist-name">Office-hour blocks</div><div class="watchlist-note">Weekly student availability</div></div><span class="watchlist-value {{ $officeHours->isNotEmpty() ? 'good' : 'warn' }}">{{ $officeHours->count() }}</span></div>
            </div>
          </section>
        </aside>
      </div>
    @endif
  </div>
</x-aop-layout>
