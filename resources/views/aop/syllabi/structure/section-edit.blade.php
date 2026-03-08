@php
  $initialMarkdown = old('content_markdown', data_get($item, 'content_markdown') ?? data_get($definition, 'default_content'));
@endphp

<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected'">
  <x-slot:title>Edit Per-Syllabus Section</x-slot:title>

  <link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css">

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Edit Per-Syllabus Section</h1>
      <p class="muted" style="margin-top:6px; max-width:900px;">
        {{ $definition->title }} for {{ $section->offering->catalogCourse->code ?? '' }} — Section {{ $section->section_code }}.
        This definition is marked as per-syllabus, so the content here can differ from other section syllabi.
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.show', $section) }}">Back to Preview</a>
    </div>
  </div>

  @if($errors->any())
    <div class="card" style="border-left:4px solid #b00020; margin-bottom:12px;">
      <strong>Please fix the highlighted issues.</strong>
      <ul style="margin:8px 0 0 18px;">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="card" style="margin-bottom:14px;">
    <h2>Definition Defaults</h2>
    <div class="muted" style="margin-top:6px;">These are the shared defaults for this section definition.</div>
    <div style="margin-top:10px; display:grid; gap:8px;">
      <div><strong>Scope:</strong> {{ $definition->scope === 'syllabus' ? 'Per-Syllabus' : 'Global' }}</div>
      <div><strong>Required:</strong> {{ $definition->is_required ? 'Yes' : 'No' }}</div>
      <div><strong>Sort Order:</strong> {{ $definition->sort_order }}</div>
      @if($definition->description)
        <div><strong>Admin Notes:</strong> {{ $definition->description }}</div>
      @endif
    </div>
    <div class="markdown-body" style="margin-top:12px; padding-top:12px; border-top:1px solid #eee;">{!! \\Illuminate\\Support\\Str::markdown($definition->default_content ?: 'No default content entered yet.', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('aop.syllabi.structure.section.update', [$section, $definition]) }}">
      @csrf
      @method('PUT')

      <label>Title Override (optional)</label>
      <input name="title_override" value="{{ old('title_override', data_get($item, 'title_override')) }}" placeholder="Leave blank to use the shared section title" />

      <div class="split">
        <div>
          <label>Sort Order</label>
          <input type="number" min="0" max="10000" name="sort_order" value="{{ old('sort_order', data_get($item, 'sort_order', data_get($definition, 'sort_order', 0))) }}" />
        </div>
        <div style="display:flex; align-items:flex-end;">
          <label style="display:flex; align-items:center; gap:8px; margin:0 0 10px;">
            <input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', data_get($item, 'is_enabled', true)) || $definition->is_required ? 'checked' : '' }} {{ $definition->is_required ? 'disabled' : '' }} />
            {{ $definition->is_required ? 'Required section (always enabled)' : 'Display this section on this syllabus' }}
          </label>
          @if($definition->is_required)
            <input type="hidden" name="is_enabled" value="1" />
          @endif
        </div>
      </div>

      <label>Per-Syllabus Content</label>
      <textarea id="syllabus-section-item-content" name="content_markdown" rows="16" placeholder="Enter the content for this syllabus section. Leave blank to fall back to the default starter content.">{{ $initialMarkdown }}</textarea>
      <div id="syllabus-section-item-content-shell" class="toast-editor-shell" style="display:none;">
        <div id="syllabus-section-item-content-editor"></div>
      </div>
      <div id="syllabus-section-item-content-help" class="muted" style="margin-top:6px; font-size:12px;">
        Stored as Markdown. If left blank, the default content from the shared definition will still be used.
      </div>

      <div style="height:12px;"></div>
      <div class="actions">
        <button class="btn" type="submit">Save Per-Syllabus Section</button>
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
