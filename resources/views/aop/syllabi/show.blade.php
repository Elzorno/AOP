<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' — '.$term->name : 'No active term selected'">
  <x-slot:title>Syllabus Preview</x-slot:title>

  @php
    $coreContent = $packet['core_content'] ?? [];
    $catalogCourse = $section->offering->catalogCourse ?? null;
  @endphp

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus Preview</h1>
      <p class="muted" style="margin-top:6px;">
        {{ $packet['course']['code'] ?? '' }} — {{ $packet['course']['title'] ?? '' }} (Section {{ $packet['section']['code'] ?? '' }})
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.core.edit', $section) }}">Edit Core Content</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.structure.create') }}">New Structure Section</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $section) }}">HTML</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $section) }}">JSON</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $section) }}">DOCX</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $section) }}">PDF</a>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px;">
    <h2>Syllabus Authoring Guide</h2>
    <p class="muted" style="margin-top:6px; max-width:980px;">
      Use this page as the syllabus control center. The fixed top content is edited through <strong>Core Content</strong>.
      Structured sections are either <strong>Global Shared</strong> or <strong>Per-Syllabus</strong>.
      Legacy blocks are still available, but they are transition-period content and should not be the main authoring path for new structure work.
    </p>
    <div class="actions" style="margin-top:10px; gap:8px;">
      <span class="badge info">Catalog Default</span>
      <span class="badge warn">Per-Syllabus Override</span>
      <span class="badge">Global Shared</span>
      <span class="badge danger">Missing</span>
    </div>
  </div>

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
        <h2>Core Syllabus Content</h2>
        <p class="muted" style="margin-top:6px; max-width:940px;">
          These are the fixed top sections of the syllabus. Each field can use the catalog default or a per-syllabus override for this section only.
        </p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.syllabi.core.edit', $section) }}">Edit This Syllabus</a>
        @if($catalogCourse)
          <a class="btn secondary" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Edit Catalog Course</a>
        @endif
      </div>
    </div>

    @if($coreContent === [])
      <p class="muted">Core syllabus content is not available for this section yet.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:220px;">Field</th>
            <th style="width:170px;">Source</th>
            <th>Current Value</th>
            <th style="width:280px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($coreContent as $field)
            @php
              $source = $field['source'] ?? 'missing';
              $value = trim((string) ($field['value'] ?? ''));
            @endphp
            <tr>
              <td>
                <strong>{{ $field['label'] ?? 'Core Field' }}</strong>
              </td>
              <td>
                @if($source === 'override')
                  <span class="badge warn">Per-Syllabus Override</span>
                @elseif($source === 'catalog')
                  <span class="badge info">Catalog Default</span>
                @else
                  <span class="badge danger">Missing</span>
                @endif
              </td>
              <td class="muted" style="white-space:pre-wrap;">
                {{ $value !== '' ? \Illuminate\Support\Str::limit($value, 280) : 'No content entered yet.' }}
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  <a class="btn secondary" href="{{ route('aop.syllabi.core.edit', $section) }}">Edit This Syllabus</a>
                  @if(!empty($field['has_override']))
                    <form method="POST" action="{{ route('aop.syllabi.core.resetField', [$section, $field['key']]) }}" style="display:inline; margin:0;" onsubmit="return confirm('Reset this field to the catalog default?');">
                      @csrf
                      <button class="btn secondary" type="submit">Reset to Catalog</button>
                    </form>
                  @endif
                  @if($catalogCourse)
                    <a class="btn secondary" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Edit Catalog Course</a>
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
        <h2>Syllabus Structure</h2>
        <p class="muted" style="margin-top:6px; max-width:900px;">
          Structured sections are the main authoring system for ordered syllabus content below the fixed top area.
          Global sections are managed once and shared everywhere. Per-syllabus sections can be customized for this section only and reset back to the shared starter content.
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
            <th style="width:170px;">Source</th>
            <th style="width:150px;">Status</th>
            <th>Content Preview</th>
            <th style="width:220px;">Actions</th>
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
                @if(($structured['source'] ?? '') === 'global')
                  <span class="badge">Global Shared</span>
                @elseif(($structured['source'] ?? '') === 'syllabus_override')
                  <span class="badge warn">Per-Syllabus Override</span>
                @else
                  <span class="badge muted">Shared Starter / Default</span>
                @endif
                <div class="muted" style="margin-top:6px; font-size:12px;">
                  {{ ($structured['scope'] ?? 'global') === 'syllabus' ? 'Per-syllabus section' : 'Global section' }}
                </div>
              </td>
              <td>
                <div style="display:grid; gap:6px;">
                  @if(!empty($structured['is_required']))
                    <span class="badge info">Required</span>
                  @endif
                  @if(!empty($structured['is_enabled']) || !empty($structured['is_required']))
                    <span class="badge success">Visible</span>
                  @else
                    <span class="badge danger">Hidden for this syllabus</span>
                  @endif
                  @if(!empty($structured['is_locked']))
                    <span class="badge warn">Protected Definition</span>
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
                    @if(!empty($structured['item_id']))
                      <form method="POST" action="{{ route('aop.syllabi.structure.section.reset', [$section, $structured['id']]) }}" style="display:inline; margin:0;" onsubmit="return confirm('Reset this section to the shared starter content?');">
                        @csrf
                        <button class="btn secondary" type="submit">Reset to Default</button>
                      </form>
                    @endif
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
                  <span class="badge success">SUCCESS</span>
                @else
                  <span class="badge danger">ERROR</span>
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
