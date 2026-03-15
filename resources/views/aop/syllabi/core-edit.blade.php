<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected'">
  <x-slot:title>Edit Core Syllabus Content</x-slot:title>

  @php
    $fieldRows = [
      [
        'name' => 'course_description_override',
        'label' => 'Course Description',
        'source' => $packet['course']['description_source'] ?? 'catalog',
        'catalog' => $packet['course']['description_catalog'] ?? '',
        'override' => $packet['course']['description_override'] ?? null,
      ],
      [
        'name' => 'course_objectives_override',
        'label' => 'Course Objectives',
        'source' => $packet['course']['objectives_source'] ?? 'catalog',
        'catalog' => $packet['course']['objectives_catalog'] ?? '',
        'override' => $packet['course']['objectives_override'] ?? null,
      ],
      [
        'name' => 'required_materials_override',
        'label' => 'Required Materials',
        'source' => $packet['course']['required_materials_source'] ?? 'catalog',
        'catalog' => $packet['course']['required_materials_catalog'] ?? '',
        'override' => $packet['course']['required_materials_override'] ?? null,
      ],
    ];
  @endphp

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Edit Core Syllabus Content</h1>
      <p class="muted" style="margin-top:6px; max-width:960px;">
        These fields control the fixed top sections in the syllabus layout for {{ $packet['course']['code'] ?? '' }} — Section {{ $section->section_code }}.
        Leave an override blank to keep using the shared catalog text for this course.
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Back to Preview</a>
      @if($catalogCourse)
        <a class="btn secondary" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Edit Catalog Course</a>
      @endif
    </div>
  </div>

  @if($errors->any())
    <div class="card panel-danger" style="margin-bottom:12px;">
      <strong>Please fix the highlighted issues.</strong>
      <ul style="margin:8px 0 0 18px;">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="card" style="margin-bottom:14px;">
    <h2>How this works</h2>
    <div class="muted" style="margin-top:8px; display:grid; gap:6px; max-width:980px;">
      <div>Catalog edits change the shared default for every syllabus that uses the course.</div>
      <div>Edits on this screen only affect this one syllabus section.</div>
      <div>Blank override fields fall back to the catalog default automatically.</div>
    </div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.syllabi.core.update', $section) }}">
      @csrf
      @method('PUT')

      @foreach($fieldRows as $row)
        @php
          $catalogText = trim((string) ($row['catalog'] ?? ''));
          $source = $row['source'] ?? 'catalog';
        @endphp
        <div style="{{ !$loop->first ? 'border-top:1px solid #eee; padding-top:18px; margin-top:18px;' : '' }}">
          <div class="row" style="align-items:flex-start; margin-bottom:8px; gap:10px;">
            <div>
              <h2 style="margin:0;">{{ $row['label'] }}</h2>
              <div class="muted" style="margin-top:6px;">
                Current source:
                @if($source === 'syllabus')
                  <span class="badge info">Per-Syllabus Override</span>
                @elseif($catalogText !== '')
                  <span class="badge info">Catalog Default</span>
                @else
                  <span class="badge danger">Missing</span>
                @endif
              </div>
            </div>
          </div>

          <label>{{ $row['label'] }} Override</label>
          <textarea name="{{ $row['name'] }}" rows="8" placeholder="Leave blank to use the catalog default for this field.">{{ old($row['name'], $row['override']) }}</textarea>
          <div class="muted" style="margin-top:6px; font-size:12px;">
            Stored as plain multiline text for this syllabus only. Blank means use the catalog default.
          </div>

          <details style="margin-top:10px;">
            <summary style="cursor:pointer; font-weight:600;">View current catalog default</summary>
            <div style="margin-top:10px; padding:12px; border:1px solid #eee; border-radius:10px; background:#fafafa; white-space:pre-wrap;">{{ $catalogText !== '' ? $catalogText : 'The catalog field is currently blank.' }}</div>
          </details>
        </div>
      @endforeach

      <div style="height:14px;"></div>
      <div class="actions">
        <button class="btn" type="submit">Save Core Content Overrides</button>
        <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Cancel</a>
      </div>
    </form>
  </div>
</x-aop-layout>
