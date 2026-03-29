<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' - '.$term->name : 'No active term selected'">
  <x-slot:title>Syllabus Preview</x-slot:title>

  @php
    $coreContent = $packet['core_content'] ?? [];
    $catalogCourse = $section->offering->catalogCourse ?? null;
    $structuredCollection = collect($structuredSections ?? []);
    $historyCollection = collect($history ?? []);
    $coreOverrideCount = collect($coreContent)->filter(fn ($field) => ($field['source'] ?? '') === 'override')->count();
    $structuredOverrideCount = $structuredCollection->filter(fn ($item) => ($item['source'] ?? '') === 'syllabus_override')->count();
    $latestDocx = $historyCollection->first(fn ($item) => strtolower((string) $item->format) === 'docx' && strtoupper((string) $item->status) === 'SUCCESS');
    $latestPdf = $historyCollection->first(fn ($item) => strtolower((string) $item->format) === 'pdf' && strtoupper((string) $item->status) === 'SUCCESS');
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Syllabus preview</div>
        <h1 class="briefing-title">{{ ($packet['course']['code'] ?? 'Course') . ' - Section ' . ($packet['section']['code'] ?? 'TBD') }}</h1>
        <p class="briefing-copy">
          {{ $packet['course']['title'] ?? 'Untitled course' }}
          @if($section->instructor?->name)
            · {{ $section->instructor->name }}
          @endif
        </p>

        <div class="status-ribbon">
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot bg-blue-500"></span>
            Export mode {{ strtoupper($exportEngine ?? 'auto') }}
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $templateExists ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
            {{ $templateExists ? 'Template installed' : 'Template missing' }}
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $coreOverrideCount === 0 ? 'bg-slate-400' : 'bg-amber-500' }}"></span>
            {{ $coreOverrideCount }} core overrides
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $structuredOverrideCount === 0 ? 'bg-slate-400' : 'bg-amber-500' }}"></span>
            {{ $structuredOverrideCount }} section overrides
          </span>
        </div>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
          <a class="btn" href="{{ route('aop.syllabi.core.edit', $section) }}">Edit Core Content</a>
          <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $section) }}">HTML</a>
          <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $section) }}">JSON</a>
          <a class="btn secondary" href="{{ route('aop.syllabi.downloadDocx', $section) }}">DOCX</a>
          <a class="btn secondary" href="{{ route('aop.syllabi.downloadPdf', $section) }}">PDF</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Section</div>
        <h2 class="watchlist-title">Current section</h2>
        <p class="watchlist-copy">Details and recent exports.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Section</div>
              <div class="watchlist-note">{{ $packet['course']['code'] ?? '' }} · {{ $packet['course']['title'] ?? '' }}</div>
            </div>
            <span class="watchlist-value good">{{ $packet['section']['code'] ?? 'TBD' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Instructor</div>
              <div class="watchlist-note">{{ $section->instructor?->email ?? 'No instructor email on file' }}</div>
            </div>
            <span class="watchlist-value {{ $section->instructor ? 'good' : 'warn' }}">{{ $section->instructor?->name ?? 'TBD' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Latest DOCX</div>
              <div class="watchlist-note">Most recent successful render</div>
            </div>
            <span class="watchlist-value {{ $latestDocx ? 'good' : 'warn' }}">{{ $latestDocx?->created_at?->format('Y-m-d') ?? 'None' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Latest PDF</div>
              <div class="watchlist-note">Most recent successful render</div>
            </div>
            <span class="watchlist-value {{ $latestPdf ? 'good' : 'warn' }}">{{ $latestPdf?->created_at?->format('Y-m-d') ?? 'None' }}</span>
          </div>
        </div>
      </aside>
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Preview</div>
          <h2 class="ledger-title">Document view</h2>
          <p class="ledger-copy">Current HTML preview.</p>
        </div>
      </div>

      <div class="toolbar-line">
        <span class="badge info">Catalog Default</span>
        <span class="badge warn">Per-Syllabus Override</span>
        <span class="badge">Global Shared</span>
        <span class="badge danger">Missing</span>
      </div>

      <div class="preview-frame">
        <iframe class="preview-iframe" srcdoc="{{ e($html) }}" title="Syllabus preview"></iframe>
      </div>
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Core content</div>
          <h2 class="ledger-title">Top-of-syllabus fields</h2>
          <p class="ledger-copy">Course description, objectives, and materials.</p>
        </div>
        <div class="actions">
          <a class="btn" href="{{ route('aop.syllabi.core.edit', $section) }}">Edit Core Content</a>
          @if($catalogCourse)
            <a class="btn secondary" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Edit Catalog Course</a>
          @endif
        </div>
      </div>

      @if($coreContent === [])
        <p class="mt-5 muted">Core syllabus content is not available for this section yet.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Field</th>
                <th>Source</th>
                <th>Current Value</th>
                <th>Actions</th>
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
                    <div class="font-semibold text-slate-900">{{ $field['label'] ?? 'Core Field' }}</div>
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
                  <td class="text-sm text-slate-600" style="white-space:pre-wrap;">
                    {{ $value !== '' ? \Illuminate\Support\Str::limit($value, 280) : 'No content entered yet.' }}
                  </td>
                  <td>
                    <div class="actions">
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.core.edit', $section) }}">Edit</a>
                      @if(!empty($field['has_override']))
                        <form method="POST" action="{{ route('aop.syllabi.core.resetField', [$section, $field['key']]) }}" onsubmit="return confirm('Reset this field to the catalog default?');">
                          @csrf
                          <button class="btn secondary sm" type="submit">Reset</button>
                        </form>
                      @endif
                      @if($catalogCourse)
                        <a class="btn secondary sm" href="{{ route('aop.catalog.edit', $catalogCourse) }}">Catalog</a>
                      @endif
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Structure</div>
          <h2 class="ledger-title">Ordered syllabus sections</h2>
          <p class="ledger-copy">Shared and section-level syllabus sections.</p>
        </div>
        <div class="actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Manage Structure</a>
          <a class="btn secondary" href="{{ route('aop.syllabi.structure.create') }}">New Structure Section</a>
        </div>
      </div>

      @if($structuredCollection->count() === 0)
        <p class="mt-5 muted">No structure sections have been defined yet.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Section</th>
                <th>Source</th>
                <th>Status</th>
                <th>Content Preview</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($structuredSections as $structured)
                <tr>
                  <td>
                    <div class="font-semibold text-slate-900">{{ $structured['title'] ?? 'Untitled Section' }}</div>
                    <div class="muted">Order: {{ $structured['sort_order'] ?? 0 }}</div>
                    @if(!empty($structured['description']))
                      <div class="mt-2 text-sm text-slate-600">{{ $structured['description'] }}</div>
                    @endif
                  </td>
                  <td>
                    @if(($structured['source'] ?? '') === 'global')
                      <span class="badge">Global Shared</span>
                    @elseif(($structured['source'] ?? '') === 'syllabus_override')
                      <span class="badge warn">Per-Syllabus Override</span>
                    @else
                      <span class="badge muted">Shared Starter</span>
                    @endif
                    <div class="mt-2 text-sm text-slate-500">
                      {{ ($structured['scope'] ?? 'global') === 'syllabus' ? 'Per-syllabus section' : 'Global section' }}
                    </div>
                  </td>
                  <td>
                    <div class="stack-grid">
                      @if(!empty($structured['is_required']))
                        <span class="badge info">Required</span>
                      @endif
                      @if(!empty($structured['is_enabled']) || !empty($structured['is_required']))
                        <span class="badge success">Visible</span>
                      @else
                        <span class="badge danger">Hidden</span>
                      @endif
                      @if(!empty($structured['is_locked']))
                        <span class="badge warn">Protected</span>
                      @endif
                    </div>
                  </td>
                  <td>
                    <div class="markdown-body markdown-preview compact">{!! $structured['content_rendered'] ?? '<p>No content entered yet.</p>' !!}</div>
                    <div class="mt-2 text-sm text-slate-500">{{ $structured['content_preview_text'] ?? 'No content entered yet.' }}</div>
                  </td>
                  <td>
                    <div class="actions">
                      @if(($structured['scope'] ?? '') === 'syllabus')
                        <a class="btn secondary sm" href="{{ route('aop.syllabi.structure.section.edit', [$section, $structured['id']]) }}">Edit</a>
                        @if(!empty($structured['item_id']))
                          <form method="POST" action="{{ route('aop.syllabi.structure.section.reset', [$section, $structured['id']]) }}" onsubmit="return confirm('Reset this section to the shared starter content?');">
                            @csrf
                            <button class="btn secondary sm" type="submit">Reset</button>
                          </form>
                        @endif
                      @else
                        <a class="btn secondary sm" href="{{ route('aop.syllabi.structure.edit', $structured['id']) }}">Edit Globally</a>
                      @endif
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Export tokens</div>
          <h2 class="ledger-title">DOCX placeholders</h2>
          <p class="ledger-copy">Placeholders available to the DOCX template.</p>
        </div>
      </div>

      @if(($templateTokenRows ?? []) === [])
        <p class="mt-5 muted">No export tokens are available for this syllabus yet.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Placeholder</th>
                <th>Purpose</th>
                <th>Current Value Preview</th>
              </tr>
            </thead>
            <tbody>
              @foreach($templateTokenRows as $row)
                <tr>
                  <td><code>{{ $row['placeholder'] }}</code></td>
                  <td>{{ $row['description'] }}</td>
                  <td class="text-sm text-slate-600" style="white-space:pre-wrap;">{{ $row['value'] !== '' ? \Illuminate\Support\Str::limit($row['value'], 180) : '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Render history</div>
          <h2 class="ledger-title">Recent exports</h2>
          <p class="ledger-copy">Recent DOCX and PDF renders.</p>
        </div>
      </div>

      @if($historyCollection->count() === 0)
        <p class="mt-5 muted">No renders recorded yet for this section.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>Format</th>
                <th>Status</th>
                <th>File</th>
                <th>Size</th>
              </tr>
            </thead>
            <tbody>
              @foreach($history as $h)
                <tr>
                  <td>{{ $h->created_at?->format('Y-m-d H:i') }}</td>
                  <td><span class="badge">{{ strtoupper($h->format) }}</span></td>
                  <td>
                    @if($h->status === 'SUCCESS')
                      <span class="badge success">Success</span>
                    @else
                      <span class="badge danger">Error</span>
                    @endif
                  </td>
                  <td class="text-sm text-slate-600">
                    {{ $h->storage_path ?? '—' }}
                    @if($h->error_message)
                      <div class="mt-2 text-sm text-red-700">{{ $h->error_message }}</div>
                    @endif
                  </td>
                  <td class="text-sm text-slate-600">
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
        </div>
      @endif
    </section>
  </div>
</x-aop-layout>
