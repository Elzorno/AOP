<x-aop-layout>
  <x-slot:title>Office Hours</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Instructor Availability</span>
      <h1 class="page-title">Office hours live inside the same active-term workflow.</h1>
      <p class="page-subtitle">
        Choose an instructor to review or update office hours for the current term. These blocks are checked against class meetings during readiness and conflict review.
      </p>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Back to Schedule Studio</a>
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
            <p class="workspace-copy">Office hours are always scoped to the active term, so choose the term first and then return here to manage instructor availability.</p>
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
            <p class="workspace-copy">Once selected, you can review office-hour coverage, lock state, and overlap issues without leaving the active term context.</p>
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
