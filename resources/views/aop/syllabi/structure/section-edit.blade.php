@php
  $initialMarkdown = old('content_markdown', data_get($item, 'content_markdown') ?? data_get($definition, 'default_content'));
@endphp

<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' - '.$term->name : 'No active term selected'">
  <x-slot:title>Edit Per-Syllabus Section</x-slot:title>

  <link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css">

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Per-syllabus section</div>
        <h1 class="briefing-title">{{ $definition->title }}</h1>
        <p class="briefing-copy">{{ $section->offering->catalogCourse->code ?? '' }} · Section {{ $section->section_code }}. Update this section for the current syllabus only.</p>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Back to Preview</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Definition defaults</div>
        <h2 class="watchlist-title">Shared section settings</h2>
        <p class="watchlist-copy">Review the shared defaults before saving a section-specific override.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Scope</div>
              <div class="watchlist-note">How this section behaves across syllabi</div>
            </div>
            <span class="watchlist-value good">{{ $definition->scope === 'syllabus' ? 'Per-Syllabus' : 'Global' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Required</div>
              <div class="watchlist-note">Whether this section must stay visible</div>
            </div>
            <span class="watchlist-value {{ $definition->is_required ? 'good' : 'warn' }}">{{ $definition->is_required ? 'Yes' : 'No' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Sort order</div>
              <div class="watchlist-note">Default display position</div>
            </div>
            <span class="watchlist-value good">{{ $definition->sort_order }}</span>
          </div>
        </div>
      </aside>
    </section>

    @if($errors->any())
      <div class="rounded-2xl border border-red-200 bg-red-50/95 p-4 shadow-sm">
        <strong class="text-sm font-semibold text-red-800">Please fix the highlighted issues.</strong>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <section class="form-card">
      <div class="form-card-header">
        <div>
          <h2 class="form-card-title">Default content</h2>
          <p class="form-card-copy">Shared starter content for this section definition.</p>
        </div>
      </div>

      @if($definition->description)
        <div class="surface-note">
          <strong class="text-slate-900">Notes</strong>
          <div class="mt-2 text-sm text-slate-600">{{ $definition->description }}</div>
        </div>
      @endif

      <div class="markdown-body mt-5">{!! \Illuminate\Support\Str::markdown($definition->default_content ?: 'No default content entered yet.', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
    </section>

    <form method="POST" action="{{ route('aop.syllabi.structure.section.update', [$section, $definition]) }}" class="form-stack">
      @csrf
      @method('PUT')

      <section class="form-card">
        <div class="form-card-header">
          <div>
            <h2 class="form-card-title">Section settings</h2>
            <p class="form-card-copy">Adjust the title, order, and visibility for this syllabus.</p>
          </div>
        </div>

        <label for="title_override">Title override</label>
        <input id="title_override" name="title_override" value="{{ old('title_override', data_get($item, 'title_override')) }}" placeholder="Leave blank to use the shared section title">

        <div class="split">
          <div>
            <label for="sort_order">Sort order</label>
            <input id="sort_order" type="number" min="0" max="10000" name="sort_order" value="{{ old('sort_order', data_get($item, 'sort_order', data_get($definition, 'sort_order', 0))) }}">
          </div>
          <div class="checkbox-stack">
            <label class="checkbox-row">
              <input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', data_get($item, 'is_enabled', true)) || $definition->is_required ? 'checked' : '' }} {{ $definition->is_required ? 'disabled' : '' }}>
              <span>{{ $definition->is_required ? 'Required section (always visible)' : 'Show this section on this syllabus' }}</span>
            </label>
            @if($definition->is_required)
              <input type="hidden" name="is_enabled" value="1">
            @endif
          </div>
        </div>
      </section>

      <section class="form-card">
        <div class="form-card-header">
          <div>
            <h2 class="form-card-title">Section content</h2>
            <p class="form-card-copy">Save syllabus-specific content for this section. Leave the editor blank to use the shared default content.</p>
          </div>
        </div>

        <label for="syllabus-section-item-content">Per-syllabus content</label>
        <textarea id="syllabus-section-item-content" name="content_markdown" rows="16" placeholder="Enter the content for this syllabus section.">{{ $initialMarkdown }}</textarea>
        <div id="syllabus-section-item-content-shell" class="toast-editor-shell" style="display:none;">
          <div id="syllabus-section-item-content-editor"></div>
        </div>
        <div id="syllabus-section-item-content-help" class="mt-2 muted">
          Markdown editor loading...
        </div>
      </section>

      <div class="actions">
        <button class="btn" type="submit">Save Section</button>
        <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Cancel</a>
      </div>
    </form>
  </div>

  <script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const textarea = document.getElementById('syllabus-section-item-content');
      const shell = document.getElementById('syllabus-section-item-content-shell');
      const editorRoot = document.getElementById('syllabus-section-item-content-editor');
      const help = document.getElementById('syllabus-section-item-content-help');

      if (!textarea || !shell || !editorRoot || !window.toastui || !window.toastui.Editor) {
        if (help) {
          help.textContent = 'Stored as Markdown. If the editor does not load, the textarea still works.';
        }
        return;
      }

      shell.style.display = 'block';
      textarea.style.display = 'none';
      textarea.setAttribute('aria-hidden', 'true');
      help.textContent = 'Stored as Markdown. Use the toolbar for headings, lists, tables, links, and emphasis.';

      const editor = new window.toastui.Editor({
        el: editorRoot,
        height: '480px',
        initialEditType: 'markdown',
        previewStyle: 'vertical',
        usageStatistics: false,
        initialValue: textarea.value || ''
      });

      const form = textarea.form;
      if (form) {
        form.addEventListener('submit', function () {
          textarea.value = editor.getMarkdown();
        });
      }
    });
  </script>
</x-aop-layout>
