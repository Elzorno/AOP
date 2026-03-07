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

<div class="card">
  <form method="POST" action="{{ $action }}">
    @csrf
    @if(($method ?? 'POST') !== 'POST')
      @method($method)
    @endif

    <label>Block Title</label>
    <input name="title" required value="{{ old('title', data_get($block, 'title')) }}" placeholder="Attendance Policy" />

    <div class="split">
      <div>
        <label>Category (optional)</label>
        <input name="category" value="{{ old('category', data_get($block, 'category')) }}" placeholder="Policies" />
      </div>
      <div>
        <label>Version (optional)</label>
        <input name="version" value="{{ old('version', data_get($block, 'version')) }}" placeholder="2026.1" />
      </div>
    </div>

    <label>Block Content</label>
    <textarea name="content_html" rows="16" placeholder="Enter the syllabus block text here.">{{ old('content_html', data_get($block, 'content_html')) }}</textarea>
    <div class="muted" style="margin-top:6px; font-size:12px;">
      This editor is plain-text first so the content can be managed safely. Template-specific styling and richer layout formatting can be adjusted in the next pass.
    </div>

    <label style="display:flex; align-items:center; gap:8px; margin-top:14px;">
      <input type="checkbox" name="is_locked" value="1" {{ old('is_locked', data_get($block, 'is_locked', false)) ? 'checked' : '' }} />
      Mark as protected (prevents deletion until unchecked)
    </label>

    <div style="height:12px;"></div>
    <div class="actions">
      <button class="btn" type="submit">{{ $submitLabel }}</button>
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Cancel</a>
    </div>
  </form>
</div>
