<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Office Hours</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Office Hours — {{ $instructor->name }}</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.officeHours.index') }}">Back</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>Status</h2>
    <p>
      @if ($lock->office_hours_locked)
        <span class="badge">LOCKED</span>
        <span style="margin-left:8px; color:var(--muted);">
          {{ $lock->office_hours_locked_at ? $lock->office_hours_locked_at->format('Y-m-d H:i') : '' }}
        </span>
      @else
        <span class="badge">UNLOCKED</span>
      @endif
    </p>

    <div class="actions" style="margin-top:10px;">
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

    <p style="margin-top:10px;">
      Locking prevents add/edit/delete for this instructor in the active term.
    </p>
  </div>

  <div class="card">
    <h2>Office Hour Blocks (Active Term)</h2>

    <table style="margin-top:10px;">
      <thead>
        <tr>
          <th>Days</th>
          <th>Time</th>
          <th>Notes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($blocks as $b)
          <tr>
            <td>{{ implode(', ', $b->days_json ?? []) }}</td>
            <td>{{ substr($b->starts_at,0,5) }}–{{ substr($b->ends_at,0,5) }}</td>
            <td>{{ $b->notes ? \Illuminate\Support\Str::limit($b->notes, 80) : '—' }}</td>
            <td style="white-space:nowrap;">
              <details>
                <summary style="cursor:pointer;">Edit</summary>

                @if ($lock->office_hours_locked)
                  <div style="margin-top:10px; color:var(--muted);">Locked. Unlock to edit.</div>
                @else
                  <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.update', [$instructor, $b]) }}">
                    @csrf
                    @method('PUT')

                    <label>Days</label>
                    <div class="split">
                      @foreach ($days as $d)
                        <label style="display:flex; gap:8px; align-items:center; margin:6px 0;">
                          <input type="checkbox" name="days[]" value="{{ $d }}" style="width:auto;" {{ in_array($d, $b->days_json ?? []) ? 'checked' : '' }} />
                          <span>{{ $d }}</span>
                        </label>
                      @endforeach
                    </div>

                    <div class="split">
                      <div>
                        <label>Start</label>
                        <input type="time" name="starts_at" required value="{{ substr($b->starts_at,0,5) }}" />
                      </div>
                      <div>
                        <label>End</label>
                        <input type="time" name="ends_at" required value="{{ substr($b->ends_at,0,5) }}" />
                      </div>
                    </div>

                    <label>Notes</label>
                    <textarea name="notes">{{ $b->notes }}</textarea>

                    <div class="actions" style="margin-top:10px;">
                      <button class="btn" type="submit">Save</button>
                    </div>
                  </form>

                  <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.destroy', [$instructor, $b]) }}" onsubmit="return confirm('Delete this office hours block?');" style="margin-top:10px;">
                    @csrf
                    @method('DELETE')
                    <button class="btn secondary" type="submit">Delete</button>
                  </form>
                @endif
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="4">No office hours yet.</td></tr>
        @endforelse
      </tbody>
    </table>

    <div style="height:14px;"></div>

    <details>
      <summary style="cursor:pointer; font-weight:700;">Add Office Hours Block</summary>

      @if ($lock->office_hours_locked)
        <div style="margin-top:10px; color:var(--muted);">Locked. Unlock to add blocks.</div>
      @else
        <form method="POST" action="{{ route('aop.schedule.officeHours.blocks.store', $instructor) }}">
          @csrf

          <label>Days</label>
          <div class="split">
            @foreach ($days as $d)
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

          <label>Notes</label>
          <textarea name="notes"></textarea>

          <div style="height:12px;"></div>
          <button class="btn" type="submit">Add Block</button>
        </form>
      @endif
    </details>
  </div>
</x-aop-layout>
