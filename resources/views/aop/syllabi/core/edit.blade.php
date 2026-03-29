@php
  $catalogCourse = $section->offering->catalogCourse ?? null;
  $coreContent = $packet['core_content'] ?? [];
  $fieldConfig = [
    'course_description' => [
      'label' => 'Course Description',
      'name' => 'course_description_override',
      'placeholder' => 'Leave blank to use the catalog course description.',
    ],
    'course_objectives' => [
      'label' => 'Course Objectives',
      'name' => 'course_objectives_override',
      'placeholder' => 'Leave blank to use the catalog course objectives.',
    ],
    'required_materials' => [
      'label' => 'Required Materials',
      'name' => 'required_materials_override',
      'placeholder' => 'Leave blank to use the catalog required materials.',
    ],
  ];
@endphp

<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' - '.$term->name : 'No active term selected'">
  <x-slot:title>Edit Core Syllabus Content</x-slot:title>

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Core content</div>
        <h1 class="briefing-title">{{ ($packet['course']['code'] ?? '') . ' - Section ' . ($packet['section']['code'] ?? '') }}</h1>
        <p class="briefing-copy">Update the fixed top portion of this syllabus. Leave any field blank to use the catalog value.</p>

        <div class="status-ribbon">
          @foreach($fieldConfig as $key => $config)
            @php $source = $coreContent[$key]['source'] ?? 'missing'; @endphp
            <span class="status-ribbon-item">
              <span class="status-ribbon-dot {{ $source === 'override' ? 'bg-amber-500' : ($source === 'catalog' ? 'bg-blue-500' : 'bg-red-500') }}"></span>
              {{ $config['label'] }}: {{ $source === 'override' ? 'Override' : ($source === 'catalog' ? 'Catalog' : 'Missing') }}
            </span>
          @endforeach
        </div>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Back to Preview</a>
          @if($catalogCourse)
            <a class="btn secondary" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Edit Catalog Course</a>
          @endif
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Current sources</div>
        <h2 class="watchlist-title">Field status</h2>
        <p class="watchlist-copy">Each field can use the catalog value or a section-specific override.</p>

        <div class="watchlist-group">
          @foreach($fieldConfig as $key => $config)
            @php $source = $coreContent[$key]['source'] ?? 'missing'; @endphp
            <div class="watchlist-item">
              <div>
                <div class="watchlist-name">{{ $config['label'] }}</div>
                <div class="watchlist-note">{{ $source === 'override' ? 'Section-specific text saved' : ($source === 'catalog' ? 'Using catalog content' : 'No content available yet') }}</div>
              </div>
              <span class="watchlist-value {{ $source === 'override' ? 'warn' : ($source === 'catalog' ? 'good' : 'danger') }}">{{ ucfirst($source) }}</span>
            </div>
          @endforeach
        </div>
      </aside>
    </section>

    <form method="POST" action="{{ route('aop.syllabi.core.update', $section) }}" class="form-stack">
      @csrf
      @method('PUT')

      @foreach($fieldConfig as $key => $config)
        @php
          $field = $coreContent[$key] ?? [];
          $source = $field['source'] ?? 'missing';
          $overrideValue = old($config['name'], $syllabus->{$config['name']} ?? '');
        @endphp
        <section class="form-card">
          <div class="form-card-header">
            <div>
              <h2 class="form-card-title">{{ $config['label'] }}</h2>
              <p class="form-card-copy">Save section-specific text here or leave the field blank to use the catalog value.</p>
            </div>
            <div class="actions">
              @if($source === 'override')
                <span class="badge warn">Per-Syllabus Override</span>
              @elseif($source === 'catalog')
                <span class="badge info">Catalog Default</span>
              @else
                <span class="badge danger">Missing</span>
              @endif
            </div>
          </div>

          <label for="{{ $config['name'] }}">Override for this syllabus</label>
          <textarea id="{{ $config['name'] }}" name="{{ $config['name'] }}" rows="10" placeholder="{{ $config['placeholder'] }}">{{ $overrideValue }}</textarea>
          <div class="mt-2 muted">Blank uses the catalog value for this section.</div>

          <div class="compare-grid">
            <div class="subcard">
              <div class="subcard-title">Current effective value</div>
              <div class="subcard-copy">{{ ($field['value'] ?? '') !== '' ? $field['value'] : 'No content entered yet.' }}</div>
            </div>
            <div class="subcard">
              <div class="subcard-title">Catalog value</div>
              <div class="subcard-copy">{{ ($field['catalog_value'] ?? '') !== '' ? $field['catalog_value'] : 'No catalog content entered yet.' }}</div>
            </div>
          </div>
        </section>
      @endforeach

      <div class="actions">
        <button class="btn" type="submit">Save Core Content</button>
        <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Cancel</a>
      </div>
    </form>
  </div>
</x-aop-layout>
