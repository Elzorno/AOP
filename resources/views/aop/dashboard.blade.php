<x-aop-layout :activeTermLabel="$activeTerm ? 'Active Term: '.$activeTerm->code.' — '.$activeTerm->name : 'No active term selected'">
  <x-slot:title>Dashboard</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Dashboard</h1>
    <div class="actions">
      <a class="btn" href="{{ route('aop.terms.index') }}">Manage Terms</a>
    </div>
  </div>

  <div class="grid">
    <div class="card col-6">
      <h2>Active Term</h2>
      @if ($activeTerm)
        <p><strong>{{ $activeTerm->code }}</strong> — {{ $activeTerm->name }}</p>
        <p>Weeks: {{ $activeTerm->weeks_in_term }} • Slot: {{ $activeTerm->slot_minutes }} min • Buffer: {{ $activeTerm->buffer_minutes }} min</p>
      @else
        <p>No active term selected. Go to <a href="{{ route('aop.terms.index') }}">Terms</a> and set one active.</p>
      @endif
    </div>

    <div class="card col-6">
      <h2>Counts</h2>
      <table>
        <tr><td>Terms</td><td>{{ $counts['terms'] }}</td></tr>
        <tr><td>Catalog Courses</td><td>{{ $counts['catalog_courses'] }}</td></tr>
        <tr><td>Instructors</td><td>{{ $counts['instructors'] }}</td></tr>
        <tr><td>Rooms</td><td>{{ $counts['rooms'] }}</td></tr>
        <tr><td>Sections</td><td>{{ $counts['sections'] }}</td></tr>
      </table>
    </div>
  </div>
</x-aop-layout>
