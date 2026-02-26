<x-aop-layout>
  <x-slot:title>Edit Term</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Edit Term</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.terms.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.terms.update', $term) }}">
      @csrf
      @method('PUT')

      <label>Term Code</label>
      <input name="code" required value="{{ old('code', $term->code) }}" />

      <label>Term Name</label>
      <input name="name" required value="{{ old('name', $term->name) }}" />

      <div class="split">
        <div>
          <label>Starts On</label>
          <input type="date" name="starts_on" value="{{ old('starts_on', optional($term->starts_on)->format('Y-m-d')) }}" />
        </div>
        <div>
          <label>Ends On</label>
          <input type="date" name="ends_on" value="{{ old('ends_on', optional($term->ends_on)->format('Y-m-d')) }}" />
        </div>
      </div>

      <div class="split">
        <div>
          <label>Weeks in Term</label>
          <input type="number" name="weeks_in_term" required value="{{ old('weeks_in_term', $term->weeks_in_term) }}" />
        </div>
        <div>
          <label>Slot Minutes</label>
          <input type="number" name="slot_minutes" required value="{{ old('slot_minutes', $term->slot_minutes) }}" />
        </div>
        <div>
          <label>Buffer Minutes</label>
          <input type="number" name="buffer_minutes" required value="{{ old('buffer_minutes', $term->buffer_minutes) }}" />
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Save Changes</button>
    </form>
  </div>
</x-aop-layout>
