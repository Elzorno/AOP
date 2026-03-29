<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' - '.$term->name">
  <x-slot:title>Office Hours</x-slot:title>

  @php
    $minutesPerWeek = $blocks->sum(function ($block) {
        $start = strtotime('1970-01-01 '.substr($block->starts_at, 0, 8));
        $end = strtotime('1970-01-01 '.substr($block->ends_at, 0, 8));
        $duration = max(0, (int) round(($end - $start) / 60));

        return $duration * count($block->days_json ?? []);
    });
    $distinctDays = $blocks->flatMap(fn ($block) => $block->days_json ?? [])->filter()->unique()->count();
    $oldBlockId = old('office_hour_block_id') ? (int) old('office_hour_block_id') : null;
    $isAddingBlock = old('office_hour_mode') === 'create';
  @endphp

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Office Hours</span>
      <h1 class="page-title">{{ $instructor->name }}</h1>
      <p class="page-subtitle">{{ $instructor->email }}</p>

      <div class="summary-strip">
        <div class="summary-stat">
          <div class="summary-stat-label">Lock State</div>
          <div class="summary-stat-value">{{ $lock->office_hours_locked ? 'Locked' : 'Open' }}</div>
          <div class="summary-stat-note">{{ $lock->office_hours_locked ? 'Edits are currently paused for this instructor.' : 'Blocks can still be added, edited, or removed.' }}</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Blocks</div>
          <div class="summary-stat-value">{{ $blocks->count() }}</div>
          <div class="summary-stat-note">Saved office-hour blocks in the active term.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Hours / Week</div>
          <div class="summary-stat-value">{{ number_format($minutesPerWeek / 60, 2) }}</div>
          <div class="summary-stat-note">{{ number_format($minutesPerWeek) }} minutes currently scheduled.</div>
        </div>
        <div class="summary-stat">
          <div class="summary-stat-label">Distinct Days</div>
          <div class="summary-stat-value">{{ $distinctDays }}</div>
          <div class="summary-stat-note">{{ $instructor->is_full_time ? 'Full-time instructors need at least 3 days.' : 'Useful for availability visibility and advising.' }}</div>
        </div>
      </div>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
        <a class="btn secondary" href="{{ route('aop.schedule.officeHours.index') }}">Choose Another Instructor</a>
        <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Schedule Studio</a>
      </div>
    </section>

    <section class="editor-layout">
      <div class="editor-main">
        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Availability status</h2>
              <p class="workspace-copy">Lock or unlock office hours for this term.</p>
            </div>
            <div class="actions">
              @if ($lock->office_hours_locked)
                <form method="POST" action="{{ route('aop.schedule.officeHours.unlock', $instructor) }}">
                  @csrf
                  <button class="btn secondary" type="submit">Unlock Office Hours</button>
                </form>
              @else
                <form method="POST" action="{{ route('aop.schedule.officeHours.lock', $instructor) }}">
                  @csrf
                  <button class="btn" type="submit">Lock Office Hours</button>
                </form>
              @endif
            </div>
          </div>

          <div class="status-note">
            {{ $lock->office_hours_locked
                ? 'Locked at '.($lock->office_hours_locked_at ? $lock->office_hours_locked_at->format('Y-m-d H:i') : 'an earlier time').'. Unlock to make further changes.'
                : 'Office hours are currently open for updates in the active term.' }}
          </div>
        </section>

        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Current office-hour blocks</h2>
              <p class="workspace-copy">Saved office-hour blocks.</p>
            </div>
          </div>

          @if($blocks->isEmpty())
            <div class="status-note">No office hours are scheduled yet for this instructor in the active term.</div>
          @else
            <div class="meeting-stack">
              @foreach ($blocks as $block)
                <div class="meeting-card">
                  <div class="meeting-head">
                    <div>
                      <div class="meeting-title">{{ implode(', ', $block->days_json ?? []) ?: 'No days selected' }}</div>
                      <div class="meeting-copy">{{ substr($block->starts_at, 0, 5) }} - {{ substr($block->ends_at, 0, 5) }}</div>
                    </div>
                    <div class="section-badges">
                      @if($lock->office_hours_locked)
                        <span class="badge muted">Locked</span>
                      @else
                        <span class="badge info">Editable</span>
                      @endif
                    </div>
                  </div>

                  @if($block->notes)
                    <div class="status-note mt-4">{{ $block->notes }}</div>
                  @endif

                  <details class="disclosure" {{ $oldBlockId === $block->id ? 'open' : '' }}>
                    <summary class="disclosure-summary">Edit office-hour block</summary>
                    <div class="disclosure-body">
                      @if ($lock->office_hours_locked)
                        <div class="status-note">Unlock office hours before editing this block.</div>
                      @else
                        <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.update', [$instructor, $block]) }}">
                          @csrf
                          @method('PUT')
                          <input type="hidden" name="office_hour_block_id" value="{{ $block->id }}">

                          <label>Days</label>
                          <div class="inline-form-grid-3">
                            @foreach ($days as $day)
                              <label class="checkbox-row">
                                <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $oldBlockId === $block->id ? (old('days', []) ?: []) : ($block->days_json ?? []), true) ? 'checked' : '' }}>
                                <span>{{ $day }}</span>
                              </label>
                            @endforeach
                          </div>

                          <div class="inline-form-grid-2">
                            <div>
                              <label for="starts_at_{{ $block->id }}">Start</label>
                              <input id="starts_at_{{ $block->id }}" type="time" name="starts_at" value="{{ $oldBlockId === $block->id ? old('starts_at') : substr($block->starts_at, 0, 5) }}" required>
                            </div>
                            <div>
                              <label for="ends_at_{{ $block->id }}">End</label>
                              <input id="ends_at_{{ $block->id }}" type="time" name="ends_at" value="{{ $oldBlockId === $block->id ? old('ends_at') : substr($block->ends_at, 0, 5) }}" required>
                            </div>
                          </div>

                          <label for="notes_{{ $block->id }}">Notes</label>
                          <input id="notes_{{ $block->id }}" name="notes" value="{{ $oldBlockId === $block->id ? old('notes') : $block->notes }}" placeholder="Optional notes">

                          <div class="section-actions">
                            <button class="btn secondary" type="submit">Save Block</button>
                          </div>
                        </form>

                        <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.destroy', [$instructor, $block]) }}" onsubmit="return confirm('Delete this office-hours block?');" class="mt-4">
                          @csrf
                          @method('DELETE')
                          <button class="btn secondary" type="submit">Delete Block</button>
                        </form>
                      @endif
                    </div>
                  </details>
                </div>
              @endforeach
            </div>
          @endif
        </section>

        <section class="workspace-card">
          <div class="workspace-header">
            <div>
              <h2 class="workspace-title">Add an office-hour block</h2>
              <p class="workspace-copy">Add another weekly block.</p>
            </div>
          </div>

          @if ($lock->office_hours_locked)
            <div class="status-note">Office hours are locked for this instructor. Unlock them first if you need to add another block.</div>
          @else
            <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.store', $instructor) }}">
              @csrf
              <input type="hidden" name="office_hour_mode" value="create">

              <label>Days</label>
              <div class="inline-form-grid-3">
                @foreach ($days as $day)
                  <label class="checkbox-row">
                    <input type="checkbox" name="days[]" value="{{ $day }}" {{ in_array($day, $isAddingBlock ? (old('days', []) ?: []) : [], true) ? 'checked' : '' }}>
                    <span>{{ $day }}</span>
                  </label>
                @endforeach
              </div>

              <div class="inline-form-grid-2">
                <div>
                  <label for="new_starts_at">Start</label>
                  <input id="new_starts_at" type="time" name="starts_at" value="{{ $isAddingBlock ? old('starts_at') : '' }}" required>
                </div>
                <div>
                  <label for="new_ends_at">End</label>
                  <input id="new_ends_at" type="time" name="ends_at" value="{{ $isAddingBlock ? old('ends_at') : '' }}" required>
                </div>
              </div>

              <label for="new_notes">Notes</label>
              <input id="new_notes" name="notes" value="{{ $isAddingBlock ? old('notes') : '' }}" placeholder="Optional notes">

              <div class="section-actions">
                <button class="btn" type="submit">Add Block</button>
              </div>
            </form>
          @endif
        </section>
      </div>

      <aside class="editor-side">
        <section class="watchlist">
          <div class="briefing-kicker">Instructor</div>
          <h2 class="watchlist-title">{{ $instructor->name }}</h2>
          <p class="watchlist-copy">{{ $instructor->email }}</p>

          <div class="watchlist-group">
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Employment</div>
                <div class="watchlist-note">Used by readiness rules</div>
              </div>
              <span class="watchlist-value {{ $instructor->is_full_time ? 'good' : 'warn' }}">{{ $instructor->is_full_time ? 'Full-time' : 'Part-time' }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Weekly hours</div>
                <div class="watchlist-note">Current office-hour coverage</div>
              </div>
              <span class="watchlist-value {{ $minutesPerWeek >= 240 ? 'good' : 'warn' }}">{{ number_format($minutesPerWeek / 60, 2) }}</span>
            </div>
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">Distinct days</div>
                <div class="watchlist-note">Target is 3 days for full-time faculty</div>
              </div>
              <span class="watchlist-value {{ $distinctDays >= 3 ? 'good' : 'warn' }}">{{ $distinctDays }}</span>
            </div>
          </div>
        </section>

        <section class="watchlist">
          <div class="briefing-kicker">Notes</div>
          <h2 class="watchlist-title">Office-hour rules</h2>
          <p class="watchlist-copy">These checks are applied when blocks are saved.</p>

          <ul class="helper-list mt-5">
            <li>End time must always be after the start time.</li>
            <li>New blocks are checked against both existing office hours and class meetings.</li>
            <li>Lock availability once the instructor’s weekly pattern is finalized.</li>
            <li>Return to readiness after changes if you are clearing publish blockers.</li>
          </ul>
        </section>
      </aside>
    </section>
  </div>
</x-aop-layout>
