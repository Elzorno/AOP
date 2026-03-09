<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected'">
  <x-slot:title>Syllabus Preview</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus Preview</h1>
      <p class="muted" style="margin-top:6px;">
        {{ $packet['course']['code'] ?? '' }} — {{ $packet['course']['title'] ?? '' }} (Section {{ $packet['section']['code'] ?? '' }})
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.structure.create') }}">New Structure Section</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $section) }}">HTML</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $section) }}">JSON</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $section) }}">DOCX</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $section) }}">PDF</a>
    </div>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71; margin-bottom:14px;">
      <strong>{{ session('status') }}</strong>
    </div>
  @endif

  <div class="card">
    <p class="muted">
      This preview uses the cleaner document-style HTML layout. With <code>AOP_SYLLABUS_EXPORT_ENGINE={{ $exportEngine ?? "auto" }}</code>, AOP can use this HTML as the preferred DOCX/PDF export source so rendered files stay closer to what you see here.
      @if($templateExists)
        The uploaded DOCX template remains available as a compatibility fallback.
      @else
        No DOCX template fallback is currently installed.
      @endif
    </p>
    <div style="margin-top:12px;">
      <iframe srcdoc="{{ e($html) }}" style="width:100%; height:1100px; border:1px solid #ddd; border-radius:10px;"></iframe>
    </div>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <div class="row" style="margin-bottom:10px; align-items:flex-start;">
      <div>
        <h2>Syllabus Structure</h2>
        <p class="muted" style="margin-top:6px; max-width:900px;">
          These are the sections currently assembled into this syllabus. Global sections are managed from the Syllabi page.
          Per-syllabus sections can be edited here for this specific section only.
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Manage Structure</a>
      </div>
    </div>

    @if(($structuredSections ?? collect())->count() === 0)
      <p class="muted">No syllabus structure sections have been defined yet.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:220px;">Section</th>
            <th style="width:130px;">Scope</th>
            <th style="width:150px;">Status</th>
            <th>Content Preview</th>
            <th style="width:170px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($structuredSections as $structured)
            <tr>
              <td>
                <strong>{{ $structured['title'] ?? 'Untitled Section' }}</strong>
                <div class="muted">Order: {{ $structured['sort_order'] ?? 0 }}</div>
                @if(!empty($structured['description']))
                  <div class="muted" style="margin-top:4px;">{{ $structured['description'] }}</div>
                @endif
              </td>
              <td>
                <span class="badge">{{ ($structured['scope'] ?? 'global') === 'syllabus' ? 'Per-Syllabus' : 'Global' }}</span>
              </td>
              <td>
                <div style="display:grid; gap:6px;">
                  @if(!empty($structured['is_required']))
                    <span class="badge" style="background:#e8f0ff; color:#1e40af;">Required</span>
                  @endif
                  @if(!empty($structured['is_enabled']) || !empty($structured['is_required']))
                    <span class="badge" style="background:#e6ffed; color:#0b6b2f;">Visible</span>
                  @else
                    <span class="badge" style="background:#ffe8e8; color:#8a0a0a;">Hidden for this syllabus</span>
                  @endif
                  @if(!empty($structured['is_locked']))
                    <span class="badge" style="background:#fff3cd; color:#7a5b00;">Protected Definition</span>
                  @endif
                </div>
              </td>
              <td>
                <div class="markdown-body markdown-preview compact">{!! $structured['content_rendered'] ?? '<p>No content entered yet.</p>' !!}</div>
                <div class="muted" style="margin-top:8px; font-size:12px;">{{ $structured['content_preview_text'] ?? 'No content entered yet.' }}</div>
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  @if(($structured['scope'] ?? 'global') === 'syllabus')
                    <a class="btn secondary" href="{{ route('aop.syllabi.structure.section.edit', [$section, $structured['id']]) }}">Edit This Syllabus</a>
                  @else
                    <a class="btn secondary" href="{{ route('aop.syllabi.structure.edit', $structured['id']) }}">Edit Globally</a>
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
        <h2>DOCX Template Tokens</h2>
        <p class="muted" style="margin-top:6px; max-width:950px;">
          Structured sections now drive the export token map. You can keep using aggregate placeholders like <code>@{{STRUCTURED_SECTIONS}}</code>,
          or place specific section tokens intentionally in the DOCX template using slug-based placeholders such as
          <code>@{{SECTION_ATTENDANCE_TITLE}}</code> and <code>@{{SECTION_ATTENDANCE_CONTENT}}</code>.
        </p>
      </div>
    </div>

    @if(($templateTokenRows ?? []) === [])
      <p class="muted">No export tokens are available for this syllabus yet.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:320px;">Placeholder</th>
            <th style="width:320px;">Purpose</th>
            <th>Current Value Preview</th>
          </tr>
        </thead>
        <tbody>
          @foreach($templateTokenRows as $row)
            <tr>
              <td><code>{{ $row['placeholder'] }}</code></td>
              <td>{{ $row['description'] }}</td>
              <td class="muted" style="white-space:pre-wrap;">{{ $row['value'] !== '' ? \Illuminate\Support\Str::limit($row['value'], 180) : '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <div class="row" style="margin-bottom:10px;">
      <div>
        <h2>Legacy Shared Blocks</h2>
        <p class="muted" style="margin-top:6px;">
          These legacy shared blocks still render below the structured sections and remain available for additional content during the transition.
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.syllabi.blocks.create') }}">New Block</a>
      </div>
    </div>

    @if(($blocks ?? collect())->count() === 0)
      <p class="muted">No shared syllabus blocks have been created yet.</p>
    @else
      @foreach($blocks as $block)
        <div style="padding:12px 0; {{ !$loop->last ? 'border-bottom:1px solid #eee;' : '' }}">
          <div class="row" style="align-items:flex-start; gap:10px;">
            <div>
              <strong>{{ $block['title'] ?: 'Untitled Block' }}</strong>
              <div class="muted">
                {{ $block['category'] ?: 'Uncategorized' }}
                @if(!empty($block['version']))
                  • Version {{ $block['version'] }}
                @endif
                @if(!empty($block['is_locked']))
                  • Protected
                @endif
              </div>
            </div>
            <div class="actions">
              <a class="btn secondary" href="{{ route('aop.syllabi.blocks.edit', $block['id']) }}">Edit</a>
            </div>
          </div>
          <div class="markdown-body" style="margin-top:8px;">{!! $block['content_rendered'] ?? '<p>—</p>' !!}</div>
        </div>
      @endforeach
    @endif
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Render History</h2>
    <p class="muted">Most recent renders (keeps up to 2 successful DOCX and 2 successful PDF per section per term).</p>

    @if(($history ?? collect())->count() === 0)
      <p class="muted" style="margin-top:10px;">No renders recorded yet for this section.</p>
    @else
      <table style="margin-top:10px;">
        <thead>
          <tr>
            <th style="width:140px;">When</th>
            <th style="width:90px;">Format</th>
            <th style="width:110px;">Status</th>
            <th>File</th>
            <th style="width:120px;">Size</th>
          </tr>
        </thead>
        <tbody>
          @foreach($history as $h)
            <tr>
              <td>{{ $h->created_at?->format('Y-m-d H:i') }}</td>
              <td><span class="badge">{{ strtoupper($h->format) }}</span></td>
              <td>
                @if($h->status === 'SUCCESS')
                  <span class="badge" style="background:#e6ffed; color:#0b6b2f;">SUCCESS</span>
                @else
                  <span class="badge" style="background:#ffe8e8; color:#8a0a0a;">ERROR</span>
                @endif
              </td>
              <td class="muted">
                {{ $h->storage_path ?? '—' }}
                @if($h->error_message)
                  <div style="color:#8a0a0a; margin-top:4px;">{{ $h->error_message }}</div>
                @endif
              </td>
              <td class="muted">
                @if($h->file_size)
                  {{ number_format($h->file_size / 1024, 1) }} KB
                @else
                  —
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
