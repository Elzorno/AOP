<x-aop-layout :activeTermLabel="$active ? 'Active Term: '.$active->code.' — '.$active->name : 'No active term selected'">
  <x-slot:title>Terms</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Terms</h1>
    <div class="actions">
      <a class="btn" href="{{ route('aop.terms.create') }}">New Term</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>Active Term</h2>
    <form method="POST" action="{{ route('aop.terms.setActive') }}">
      @csrf
      <label for="term_id">Select active term</label>
      <select name="term_id" id="term_id" required>
        <option value="" disabled {{ $active ? '' : 'selected' }}>Choose a term…</option>
        @foreach ($terms as $t)
          <option value="{{ $t->id }}" {{ $active && $active->id === $t->id ? 'selected' : '' }}>
            {{ $t->code }} — {{ $t->name }}
          </option>
        @endforeach
      </select>
      <div style="height:10px;"></div>
      <button class="btn" type="submit">Set Active</button>
    </form>
  </div>

  <div class="card">
    <h2>All Terms</h2>
    <table>
      <thead>
        <tr>
          <th>Code</th>
          <th>Name</th>
          <th>Dates</th>
          <th>Weeks</th>
          <th>Slot</th>
          <th>Buffer</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($terms as $t)
          <tr>
            <td>
              {{ $t->code }}
              @if ($active && $active->id === $t->id)
                <span class="badge">Active</span>
              @endif
            </td>
            <td>{{ $t->name }}</td>
            <td>{{ $t->starts_on?->format('Y-m-d') ?? '—' }} to {{ $t->ends_on?->format('Y-m-d') ?? '—' }}</td>
            <td>{{ $t->weeks_in_term }}</td>
            <td>{{ $t->slot_minutes }}m</td>
            <td>{{ $t->buffer_minutes }}m</td>
            <td><a class="btn link" href="{{ route('aop.terms.edit', $t) }}">Edit</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</x-aop-layout>
