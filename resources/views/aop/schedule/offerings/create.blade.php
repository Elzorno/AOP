<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>New Offering</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>New Offering</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.offerings.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.schedule.offerings.store') }}">
      @csrf

      <label>Catalog Course</label>
      <select name="catalog_course_id" required>
        <option value="" disabled selected>Choose a course…</option>
        @foreach ($courses as $c)
          <option value="{{ $c->id }}">{{ $c->code }} — {{ $c->title }}</option>
        @endforeach
      </select>

      <label>Delivery Method (optional)</label>
      <input name="delivery_method" value="{{ old('delivery_method') }}" placeholder="In-person / Online / Hybrid" />

      <label>Notes (optional)</label>
      <textarea name="notes">{{ old('notes') }}</textarea>

      <div class="split">
        <div>
          <label>Prereq Override (optional)</label>
          <textarea name="prereq_override">{{ old('prereq_override') }}</textarea>
        </div>
        <div>
          <label>Coreq Override (optional)</label>
          <textarea name="coreq_override">{{ old('coreq_override') }}</textarea>
        </div>
      </div>

      <div style="height:12px;"></div>
      <button class="btn" type="submit">Create Offering</button>
    </form>
  </div>
</x-aop-layout>
