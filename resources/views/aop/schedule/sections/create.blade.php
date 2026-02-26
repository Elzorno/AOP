<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>New Section</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>New Section</h1>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Back</a>
    </div>
  </div>

  <div class="card">
    @if ($offerings->count() === 0)
      <p>No offerings exist for this term yet. Create an offering first.</p>
      <a class="btn" href="{{ route('aop.schedule.offerings.create') }}">Create Offering</a>
    @else
      <form method="POST" action="{{ route('aop.schedule.sections.store') }}">
        @csrf

        <label>Offering (course in this term)</label>
        <select name="offering_id" required>
          <option value="" disabled selected>Choose an offering…</option>
          @foreach ($offerings as $o)
            <option value="{{ $o->id }}">{{ $o->catalogCourse->code }} — {{ $o->catalogCourse->title }}</option>
          @endforeach
        </select>

        <div class="split">
          <div>
            <label>Section Code</label>
            <input name="section_code" required value="{{ old('section_code') }}" placeholder="01" />
          </div>
          <div>
            <label>Instructor (optional)</label>
            <select name="instructor_id">
              <option value="">—</option>
              @foreach ($instructors as $i)
                <option value="{{ $i->id }}">{{ $i->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label>Modality</label>
            <select name="modality" required>
              @foreach ($modalities as $m)
                <option value="{{ $m->value }}">{{ $m->value }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <label>Notes (optional)</label>
        <textarea name="notes">{{ old('notes') }}</textarea>

        <div style="height:12px;"></div>
        <button class="btn" type="submit">Create Section</button>
      </form>
    @endif
  </div>
</x-aop-layout>
