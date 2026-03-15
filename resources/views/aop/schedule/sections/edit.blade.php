<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Edit Section</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Section</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Back</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>{{ $section->offering->catalogCourse->code }} — {{ $section->offering->catalogCourse->title }} ({{ $section->section_code }})</h2>

    <form method="POST" action="{{ route('aop.schedule.sections.update', $section) }}">
      @csrf
      @method('PUT')

      <div class="split">
        <div>
          <label>Section Code</label>
          <input name="section_code" required value="{{ old('section_code', $section->section_code) }}" />
        </div>
        <div>
          <label>Instructor</label>
          <select name="instructor_id">
            <option value="">—</option>
            @foreach ($instructors as $i)
              <option value="{{ $i->id }}" {{ $section->instructor_id === $i->id ? 'selected' : '' }}>{{ $i->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label>Modality</label>
          <select name="modality" required>
            @foreach ($modalities as $m)
              <option value="{{ $m->value }}" {{ $section->modality->value === $m->value ? 'selected' : '' }}>{{ $m->value }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <label>Notes (optional)</label>
      <textarea name="notes">{{ old('notes', $section->notes) }}</textarea>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Save Section</button>
    </form>
  </div>

  <div class="card">
    <h2>Meeting Blocks</h2>
    <p>Rooms are required for in-person/hybrid sections. Online sections do not require rooms.</p>

    <table style="margin-top:10px;">
      <thead>
        <tr>
          <th>Type</th>
          <th>Days</th>
          <th>Time</th>
          <th>Room</th>
          <th>Notes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($section->meetingBlocks as $mb)
          <tr>
            <td>{{ $mb->type->value }}</td>
            <td>{{ implode(', ', $mb->days_json ?? []) }}</td>
            <td>{{ substr($mb->starts_at,0,5) }}–{{ substr($mb->ends_at,0,5) }}</td>
            <td>{{ $mb->room?->name ?? '—' }}</td>
            <td>{{ $mb->notes ? \Illuminate\Support\Str::limit($mb->notes, 60) : '—' }}</td>
            <td style="white-space:nowrap;">
              <details>
                <summary style="cursor:pointer;">Edit</summary>
                <form method="POST" action="{{ route('aop.schedule.meetingBlocks.update', [$section, $mb]) }}">
                  @csrf
                  @method('PUT')

                  <label>Type</label>
                  <select name="type" required>
                    @foreach (\App\Enums\MeetingBlockType::cases() as $t)
                      <option value="{{ $t->value }}" {{ $mb->type->value === $t->value ? 'selected' : '' }}>{{ $t->value }}</option>
                    @endforeach
                  </select>

                  <label>Days</label>
                  @php $days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; @endphp
                  <div class="split">
                    @foreach ($days as $d)
                      <label style="display:flex; gap:8px; align-items:center; margin:6px 0;">
                        <input type="checkbox" name="days[]" value="{{ $d }}" style="width:auto;" {{ in_array($d, $mb->days_json ?? []) ? 'checked' : '' }} />
                        <span>{{ $d }}</span>
                      </label>
                    @endforeach
                  </div>

                  <div class="split">
                    <div>
                      <label>Start</label>
                      <input type="time" name="starts_at" required value="{{ substr($mb->starts_at,0,5) }}" />
                    </div>
                    <div>
                      <label>End</label>
                      <input type="time" name="ends_at" required value="{{ substr($mb->ends_at,0,5) }}" />
                    </div>
                  </div>

                  <label>Room</label>
                  <select name="room_id">
                    <option value="">—</option>
                    @foreach (\App\Models\Room::where('is_active', true)->orderBy('name')->get() as $r)
                      <option value="{{ $r->id }}" {{ $mb->room_id === $r->id ? 'selected' : '' }}>{{ $r->name }}</option>
                    @endforeach
                  </select>

                  <label>Notes</label>
                  <textarea name="notes">{{ $mb->notes }}</textarea>

                  <button class="btn" type="submit">Save Block</button>
                </form>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="6">No meeting blocks yet.</td></tr>
        @endforelse
      </tbody>
    </table>

    <div style="height:12px;"></div>

    <div style="height:12px;"></div>

    <details>
      <summary style="cursor:pointer; font-weight:700;">Add Meeting Block</summary>
      
      <div style="margin: 15px 0; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
        <h3 style="margin-top:0; font-size:14px;">Suggest Slot</h3>
        <div style="display: flex; gap: 10px; align-items: flex-end;">
            <div>
                <label style="font-size:12px;">Duration (min)</label>
                <input type="number" id="suggestDuration" value="60" style="width: 80px;" />
            </div>
            <div>
                <label style="font-size:12px;">Days (optional, e.g. Mon,Wed,Fri)</label>
                <input type="text" id="suggestDays" placeholder="Mon,Wed,Fri" />
            </div>
            <button type="button" class="btn secondary" onclick="fetchSuggestions()">Find Open Slots</button>
        </div>
        <div id="suggestionsList" style="margin-top: 10px; font-size:13px;"></div>
      </div>

      <form method="POST" id="addBlockForm" action="{{ route('aop.schedule.meetingBlocks.store', $section) }}">
        @csrf

        <label>Type</label>
        <select name="type" required>
          @foreach (\App\Enums\MeetingBlockType::cases() as $t)
            <option value="{{ $t->value }}">{{ $t->value }}</option>
          @endforeach
        </select>

        <label>Days</label>
        @php $days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; @endphp
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

        <label>Room</label>
        <select name="room_id">
          <option value="">—</option>
          @foreach (\App\Models\Room::where('is_active', true)->orderBy('name')->get() as $r)
            <option value="{{ $r->id }}">{{ $r->name }}</option>
          @endforeach
        </select>

        <label>Notes</label>
        <textarea name="notes"></textarea>

        <div style="height:12px;"></div>
        <button class="btn" type="submit">Add Block</button>
      </form>
    </details>
  </div>

  <script>
    function fetchSuggestions() {
      const dur = document.getElementById('suggestDuration').value || 60;
      const days = document.getElementById('suggestDays').value;
      const url = "{{ route('aop.schedule.sections.suggest', $section) }}?duration=" + dur + "&days=" + encodeURIComponent(days);
      const list = document.getElementById('suggestionsList');
      
      list.innerHTML = '<em>Searching...</em>';

      fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.suggestions || data.suggestions.length === 0) {
                list.innerHTML = '<em>No conflicts-free slots found. Try a different duration or days.</em>';
                return;
            }

            let html = '<ul style="padding-left: 20px; list-style-type: disc;">';
            data.suggestions.forEach((s, idx) => {
                const daysStr = s.days.join(',');
                const rooms = s.available_rooms.length > 0 ? s.available_rooms.map(r => r.name).join(', ') : 'None';
                html += `<li style="margin-bottom:6px;">
                    <strong>${daysStr}</strong> ${s.starts_at} - ${s.ends_at} (Open Rooms: ${rooms}) 
                    <button type="button" class="btn secondary link" style="font-size:11px; margin-left:10px" onclick="applySuggestion('${daysStr}', '${s.starts_at}', '${s.ends_at}', ${s.available_rooms.length > 0 ? s.available_rooms[0].id : 'null'})">Use Slot</button>
                </li>`;
            });
            html += '</ul>';
            list.innerHTML = html;
        });
    }

    function applySuggestion(daysStr, start, end, roomId) {
        const form = document.getElementById('addBlockForm');
        
        // Reset checkboxes
        form.querySelectorAll('input[type="checkbox"][name="days[]"]').forEach(cb => cb.checked = false);
        
        // Check matching days
        const days = daysStr.split(',');
        days.forEach(d => {
            const cb = form.querySelector('input[type="checkbox"][name="days[]"][value="' + d + '"]');
            if (cb) cb.checked = true;
        });

        // Set times
        form.querySelector('input[name="starts_at"]').value = start;
        form.querySelector('input[name="ends_at"]').value = end;

        // Set room if available
        if (roomId !== null) {
            form.querySelector('select[name="room_id"]').value = roomId;
        }

        alert('Form filled with suggestion!');
    }
  </script>
</x-aop-layout>
