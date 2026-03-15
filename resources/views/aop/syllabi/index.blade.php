<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected'">
  <x-slot:title>Syllabi</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabi</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
    </div>
  </div>

  @if(session('status'))
    <div class="card panel-success">
      <strong>{{ session('status') }}</strong>
    </div>
    <div class="stack-sm"></div>
  @endif

  <div class="card" style="margin-bottom:14px;">
    <h2>Authoring Quick Guide</h2>
    <p class="muted" style="margin-top:6px; max-width:920px;">
      Open an individual syllabus to manage section-specific content. The <strong>Core Content</strong> editor controls Course Description, Course Objectives, and Required Materials for one section.
      Structured sections handle ordered syllabus sections below the fixed top content. Legacy shared blocks are still available during the transition.
    </p>
  </div>

  <div class="card">
    <h2>Template</h2>
    <p class="muted">AOP can now prefer HTML-aligned syllabus exports so DOCX/PDF output tracks the cleaner in-app layout more closely. Template-based export remains available for compatibility and fallback.</p>

    <div style="margin-top:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <div>
        <span class="badge">Export Mode: {{ strtoupper($exportEngine ?? 'AUTO') }}</span>
      </div>
      <div>
        @if($templateExists)
          <span class="badge">Template: Installed</span>
        @else
          <span class="badge danger">Template: Missing</span>
        @endif
      </div>

      <form method="POST" action="{{ route('aop.syllabi.template.upload') }}" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; margin:0; flex-wrap:wrap;">
        @csrf
        <input type="file" name="template" accept=".docx" required>
        <button class="btn" type="submit">Upload Template</button>
      </form>
    </div>

    @error('template')
      <div class="muted" style="margin-top:8px; color:#b00020;">{{ $message }}</div>
    @enderror

    <div class="muted" style="margin-top:10px; font-size:12px;">
      In <code>AOP_SYLLABUS_EXPORT_ENGINE=auto</code>, AOP tries HTML-aligned DOCX/PDF export first and falls back to the uploaded DOCX template when needed.
      Install <code>pandoc</code> and/or <code>libreoffice</code> for the broadest export support.
    </div>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <div class="row" style="margin-bottom:10px; align-items:flex-start;">
      <div>
        <h2>Syllabus Structure Builder</h2>
        <p class="muted" style="margin-top:6px; max-width:900px;">
          Define the sections that make up a syllabus and decide whether each section is global for every syllabus or editable per section syllabus.
          Global sections share the same content everywhere; per-syllabus sections use a shared starter template but can be customized from each syllabus preview.
        </p>
      </div>
      <div class="actions">
        <a class="btn" href="{{ route('aop.syllabi.structure.create') }}">New Structure Section</a>
      </div>
    </div>

    @if(($definitions ?? collect())->count() === 0)
      <p class="muted">No syllabus structure sections have been created yet.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:240px;">Section</th>
            <th style="width:130px;">Scope</th>
            <th style="width:90px;">Order</th>
            <th>Default Content Preview</th>
            <th style="width:150px;">Status</th>
            <th style="width:180px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($definitions as $definition)
            <tr>
              <td>
                <strong>{{ $definition->title }}</strong>
                <div class="muted">Slug: {{ $definition->slug }}</div>
                @if($definition->category)
                  <div class="muted">Category: {{ $definition->category }}</div>
                @endif
                @if($definition->description)
                  <div class="muted" style="margin-top:4px;">{{ $definition->description }}</div>
                @endif
              </td>
              <td>
                <span class="badge">{{ $definition->scope === 'syllabus' ? 'Per-Syllabus' : 'Global' }}</span>
              </td>
              <td>{{ $definition->sort_order }}</td>
              <td>
                <div class="markdown-body markdown-preview compact">{!! $definition->content_rendered !!}</div>
                <div class="muted" style="margin-top:8px; font-size:12px;">{{ $definition->content_preview_text }}</div>
              </td>
              <td>
                <div style="display:grid; gap:6px;">
                  @if($definition->is_required)
                    <span class="badge info">Required</span>
                  @else
                    <span class="badge muted">Optional</span>
                  @endif

                  @if($definition->is_active)
                    <span class="badge success">Active</span>
                  @else
                    <span class="badge danger">Inactive</span>
                  @endif

                  @if($definition->is_locked)
                    <span class="badge warn">Protected</span>
                  @endif
                </div>
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  <a class="btn secondary" href="{{ route('aop.syllabi.structure.edit', $definition) }}">Edit</a>
                  @if(!$definition->is_locked)
                    <form method="POST" action="{{ route('aop.syllabi.structure.destroy', $definition) }}" style="display:inline; margin:0;" onsubmit="return confirm('Delete this syllabus structure section?');">
                      @csrf
                      @method('DELETE')
                      <button class="btn secondary" type="submit">Delete</button>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <div class="row" style="margin-bottom:10px; align-items:flex-start;">
      <div>
        <h2>Legacy Shared Syllabus Blocks</h2>
        <p class="muted" style="margin-top:6px; max-width:850px;">
          These shared blocks remain available and still flow into JSON, preview, and export replacement data.
          The new structure builder should be used for intentional section ordering and global-versus-per-syllabus control.
        </p>
      </div>
      <div class="actions">
        <a class="btn" href="{{ route('aop.syllabi.blocks.create') }}">New Block</a>
      </div>
    </div>

    @if(($blocks ?? collect())->count() === 0)
      <p class="muted">No syllabus blocks have been created yet.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:220px;">Block</th>
            <th style="width:150px;">Category</th>
            <th>Content Preview</th>
            <th style="width:120px;">Status</th>
            <th style="width:170px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($blocks as $block)
            <tr>
              <td>
                <strong>{{ $block->title }}</strong>
                @if($block->version)
                  <div class="muted">Version: {{ $block->version }}</div>
                @endif
                <div class="muted">Updated {{ $block->updated_at?->format('Y-m-d H:i') }}</div>
              </td>
              <td>{{ $block->category ?: '—' }}</td>
              <td>
                <div class="markdown-body markdown-preview compact">{!! $block->content_rendered !!}</div>
                <div class="muted" style="margin-top:8px; font-size:12px;">{{ $block->content_preview_text }}</div>
              </td>
              <td>
                @if($block->is_locked)
                  <span class="badge warn">Protected</span>
                @else
                  <span class="badge muted">Editable</span>
                @endif
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  <a class="btn secondary" href="{{ route('aop.syllabi.blocks.edit', $block) }}">Edit</a>
                  @if(!$block->is_locked)
                    <form method="POST" action="{{ route('aop.syllabi.blocks.destroy', $block) }}" style="display:inline; margin:0;" onsubmit="return confirm('Delete this syllabus block?');">
                      @csrf
                      @method('DELETE')
                      <button class="btn secondary" type="submit">Delete</button>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Sections</h2>

    @if(!$term)
      <p class="muted">Set an active term to generate syllabi.</p>
    @elseif($sections->count() === 0)
      <p class="muted">No sections found for the active term.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Instructor</th>
            <th style="width:360px;">Outputs</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sections as $s)
            <tr>
              <td>
                <strong>{{ $s->offering->catalogCourse->code }}</strong>
                <div class="muted">{{ $s->offering->catalogCourse->title }}</div>
              </td>
              <td>
                <span class="badge">{{ $s->section_code }}</span>
                <div class="muted">{{ $s->modality }}</div>
              </td>
              <td>
                {{ $s->instructor?->name ?? 'TBD' }}
                <div class="muted">{{ $s->instructor?->email ?? '' }}</div>
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  <a class="btn secondary" href="{{ route('aop.syllabi.show', $s) }}">View</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.core.edit', $s) }}">Core Content</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $s) }}">HTML</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $s) }}">JSON</a>
                  <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $s) }}">DOCX</a>
                  <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $s) }}">PDF</a>
                </div>

                @php
                  $renderMap = $latestBySection ?? [];
                  $docxRender = $renderMap[$s->id . ':docx'] ?? null;
                  $pdfRender = $renderMap[$s->id . ':pdf'] ?? null;
                  $docxAt = ($docxRender?->completed_at ?? $docxRender?->created_at);
                  $pdfAt = ($pdfRender?->completed_at ?? $pdfRender?->created_at);
                @endphp
                <div class="muted" style="margin-top:8px; font-size:12px;">
                  <div>Last DOCX: {{ $docxAt?->format('Y-m-d H:i') ?? '—' }}</div>
                  <div>Last PDF: {{ $pdfAt?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
