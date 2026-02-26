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

    <details>
      <summary style="cursor:pointer; font-weight:700;">Add Meeting Block</summary>
      <form method="POST" action="{{ route('aop.schedule.meetingBlocks.store', $section) }}">
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
</x-aop-layout>
