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
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  <div class="card">
    <h2>Template</h2>
    <p class="muted">DOCX files are generated from the uploaded DOCX template, and PDFs are produced by converting that rendered DOCX through LibreOffice.</p>

    <div style="margin-top:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <div>
        @if($templateExists)
          <span class="badge">Template: Installed</span>
        @else
          <span class="badge" style="background:#ffe8e8;">Template: Missing</span>
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
      Tip: Install LibreOffice in the LXC for PDF conversion: <code>apt-get install -y libreoffice</code>
    </div>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <div class="row" style="margin-bottom:10px; align-items:flex-start;">
      <div>
        <h2>Syllabus Blocks</h2>
        <p class="muted" style="margin-top:6px; max-width:850px;">
          Shared syllabus blocks are editable here. They flow into the JSON packet, browser preview, and template replacement data for every syllabus.
          Block content is stored as Markdown and rendered safely for preview.
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
                  <span class="badge" style="background:#fff3cd; color:#7a5b00;">Protected</span>
                @else
                  <span class="badge">Editable</span>
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
