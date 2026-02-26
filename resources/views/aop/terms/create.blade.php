<x-aop-layout>
  <x-slot:title>New Term</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>New Term</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.terms.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.terms.store') }}">
      @csrf
      <label>Term Code</label>
      <input name="code" required placeholder="AU25" value="{{ old('code') }}" />

      <label>Term Name</label>
      <input name="name" required placeholder="Autumn 2025" value="{{ old('name') }}" />

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
          <input type="number" name="weeks_in_term" required value="{{ old('weeks_in_term', 15) }}" />
        </div>
        <div>
          <label>Slot Minutes</label>
          <input type="number" name="slot_minutes" required value="{{ old('slot_minutes', 15) }}" />
        </div>
        <div>
          <label>Buffer Minutes</label>
          <input type="number" name="buffer_minutes" required value="{{ old('buffer_minutes', 10) }}" />
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Create Term</button>
    </form>
  </div>
</x-aop-layout>
