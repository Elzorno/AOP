<x-aop-layout :activeTermLabel="($activeTerm ? 'Active Term: '.$activeTerm->code.' - '.$activeTerm->name : 'No active term selected')">
  <x-slot:title>Lab Techs</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Lab Techs</h1>
      <p>Manage lab coverage staff and their weekly HR hour limits.</p>
      @if($activeTerm)
        <p class="muted">Current workload reflects open labs in {{ $activeTerm->code }}.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.openLabs.legacy') }}">Open Labs</a>
      <a class="btn" href="{{ route('aop.labtechs.create') }}">New Lab Tech</a>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Weekly HR Limit</th>
          <th>Open Labs</th>
          <th>Assigned Hours</th>
          <th>Remaining</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($labTechs as $labTech)
          @php
            $workload = $workloadRows->get($labTech->id);
            $remainingHours = $workload['remaining_hours'] ?? null;
          @endphp
          <tr>
            <td>{{ $labTech->name }}</td>
            <td>{{ $labTech->email ?: '—' }}</td>
            <td>{{ number_format((float) $labTech->weekly_hour_limit, 2) }} hrs</td>
            <td>{{ $workload['open_lab_count'] ?? 0 }}</td>
            <td>{{ number_format((float) ($workload['assigned_hours'] ?? 0), 2) }} hrs</td>
            <td>
              @if($remainingHours === null)
                —
              @else
                {{ number_format((float) $remainingHours, 2) }} hrs
              @endif
            </td>
            <td>
              @if(!$labTech->is_active)
                <span class="badge muted">Inactive</span>
              @elseif(($workload['is_over_limit'] ?? false))
                <span class="badge danger">Over limit</span>
              @else
                <span class="badge success">Available</span>
              @endif
            </td>
            <td style="text-align:right;">
              <a class="btn secondary sm" href="{{ route('aop.labtechs.edit', $labTech) }}">Edit</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="8">No lab techs yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-aop-layout>
