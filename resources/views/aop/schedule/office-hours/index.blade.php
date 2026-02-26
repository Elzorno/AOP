<x-aop-layout>
  <x-slot:title>Office Hours</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Office Hours</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back</a>
    </div>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before managing office hours.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>

      <div style="height:12px;"></div>

      <h2>Select Instructor</h2>
      <form method="GET" action="{{ route('aop.schedule.officeHours.index') }}">
        <label>Instructor</label>
        <select onchange="if(this.value){ window.location = '{{ url('/aop/schedule/office-hours') }}/' + this.value; }">
          <option value="">— Select —</option>
          @foreach ($instructors as $i)
            <option value="{{ $i->id }}">{{ $i->name }}</option>
          @endforeach
        </select>
        <p style="margin-top:8px;">Office hours are scoped to the active term.</p>
      </form>
    @endif
  </div>
</x-aop-layout>
