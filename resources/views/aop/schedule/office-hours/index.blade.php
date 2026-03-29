<x-aop-layout>
  <x-slot:title>Office Hours</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Office Hours</span>
      <h1 class="page-title">Office hours for the active term</h1>
      <p class="page-subtitle">Choose an instructor to review or update weekly availability.</p>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
        @if($term)
          <a class="btn secondary" href="{{ route('aop.schedule.readiness.index') }}">Readiness</a>
        @else
          <a class="btn secondary" href="{{ route('aop.terms.index') }}">Manage Terms</a>
        @endif
      </div>
    </section>

    @if (!$term)
      <section class="workspace-card">
        <div class="workspace-header">
          <div>
            <h2 class="workspace-title">No active term selected</h2>
            <p class="workspace-copy">Set the term, then manage instructor availability.</p>
          </div>
          <div class="actions">
            <a class="btn" href="{{ route('aop.terms.index') }}">Open Terms</a>
          </div>
        </div>
      </section>
    @else
      <section class="workspace-card">
        <div class="workspace-header">
          <div>
            <h2 class="workspace-title">Select an instructor</h2>
            <p class="workspace-copy">Open an instructor to edit office hours.</p>
          </div>
        </div>

        <form method="GET" action="{{ route('aop.schedule.officeHours.index') }}">
          <label for="office_hours_instructor">Instructor</label>
          <select
            id="office_hours_instructor"
            onchange="if(this.value){ window.location = '{{ url('/aop/schedule/office-hours') }}/' + this.value; }"
          >
            <option value="">Choose an instructor</option>
            @foreach ($instructors as $instructor)
              <option value="{{ $instructor->id }}">{{ $instructor->name }}</option>
            @endforeach
          </select>
          <p class="table-note">Active term: <strong>{{ $term->code }}</strong> - {{ $term->name }}</p>
        </form>
      </section>
    @endif
  </div>
</x-aop-layout>
