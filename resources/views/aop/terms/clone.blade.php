<x-aop-layout>
  <x-slot:title>Clone Term Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Clone Term Schedule</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.terms.index') }}">Back</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>Source Term</h2>
    <p><strong>{{ $sourceTerm->code }}</strong> — {{ $sourceTerm->name }}</p>
    <p>
      Dates: {{ $sourceTerm->starts_on?->format('Y-m-d') ?? '—' }} to {{ $sourceTerm->ends_on?->format('Y-m-d') ?? '—' }}<br>
      Weeks: {{ $sourceTerm->weeks_in_term }} · Slot: {{ $sourceTerm->slot_minutes }}m · Buffer: {{ $sourceTerm->buffer_minutes }}m
    </p>
    <p>This creates a brand-new target term and copies offerings, sections, and meeting blocks into it. Readiness can be run afterward to validate minutes and conflicts.</p>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.terms.clone.store', $sourceTerm) }}">
      @csrf

      <label>New Term Code</label>
      <input name="code" required placeholder="AU26" value="{{ old('code') }}" />

      <label>New Term Name</label>
      <input name="name" required placeholder="Autumn 2026" value="{{ old('name') }}" />

      <div class="split">
        <div>
          <label>Starts On</label>
          <input type="date" name="starts_on" value="{{ old('starts_on') }}" />
        </div>
        <div>
          <label>Ends On</label>
          <input type="date" name="ends_on" value="{{ old('ends_on') }}" />
        </div>
      </div>

      <div class="split">
        <div>
          <label>Weeks in Term</label>
          <input type="number" name="weeks_in_term" required value="{{ old('weeks_in_term', $sourceTerm->weeks_in_term) }}" />
        </div>
        <div>
          <label>Slot Minutes</label>
          <input type="number" name="slot_minutes" required value="{{ old('slot_minutes', $sourceTerm->slot_minutes) }}" />
        </div>
        <div>
          <label>Buffer Minutes</label>
          <input type="number" name="buffer_minutes" required value="{{ old('buffer_minutes', $sourceTerm->buffer_minutes) }}" />
        </div>
      </div>

      <div style="height:12px;"></div>
      <label style="display:flex; gap:10px; align-items:flex-start; color:inherit;">
        <input type="checkbox" name="copy_instructor_assignments" value="1" {{ old('copy_instructor_assignments') ? 'checked' : '' }} style="width:auto; margin-top:2px;" />
        <span>
          Copy instructor assignments from the source term.
          <span style="display:block; color:var(--muted); font-size:12px; margin-top:4px;">Default is off so the new term starts unassigned.</span>
        </span>
      </label>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Create Term and Clone Schedule</button>
    </form>
  </div>
</x-aop-layout>
