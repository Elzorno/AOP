<x-aop-layout>
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Schedule</h1>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before scheduling.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      @if($latestPublication)
        <p class="muted">Published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
      @else
        <p class="muted">Published: <span class="badge">None</span></p>
      @endif
      <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
        <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
      </div>
    @endif
  </div>
</x-aop-layout>
