<x-aop-layout :activeTermLabel="$term ? 'Active Term: '.$term->code.' - '.$term->name : 'No active term selected'">
  <x-slot:title>Syllabi</x-slot:title>

  @php
    $definitionsCount = ($definitions ?? collect())->count();
    $blocksCount = ($blocks ?? collect())->count();
    $sectionsCount = ($sections ?? collect())->count();
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Syllabi workspace</div>
        <h1 class="briefing-title">{{ $term ? 'Manage syllabus content and exports for '.$term->code.'.' : 'Set an active term to work with syllabi.' }}</h1>
        <p class="briefing-copy">
          {{ $term
              ? 'Manage the export template, structure sections, shared blocks, and section outputs from one place.'
              : 'Choose the active term first, then return here to manage section syllabi and exports.' }}
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
            <span class="status-ribbon-dot bg-slate-500"></span>
            {{ $definitionsCount }} structure sections
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot bg-slate-500"></span>
            {{ $sectionsCount }} sections
          </span>
        </div>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
          <a class="btn" href="{{ route('aop.syllabi.structure.create') }}">New Structure Section</a>
          <a class="btn secondary" href="{{ route('aop.syllabi.blocks.create') }}">New Shared Block</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">At a glance</div>
        <h2 class="watchlist-title">Current syllabus setup</h2>
        <p class="watchlist-copy">Template status, structure coverage, and section volume for the active term.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Active term</div>
              <div class="watchlist-note">{{ $term ? $term->name : 'Required before generating section syllabi' }}</div>
            </div>
            <span class="watchlist-value {{ $term ? 'good' : 'warn' }}">{{ $term ? $term->code : 'Unset' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Template</div>
              <div class="watchlist-note">DOCX fallback availability</div>
            </div>
            <span class="watchlist-value {{ $templateExists ? 'good' : 'warn' }}">{{ $templateExists ? 'Installed' : 'Missing' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Structure sections</div>
              <div class="watchlist-note">Ordered sections available to all syllabi</div>
            </div>
            <span class="watchlist-value good">{{ $definitionsCount }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Shared blocks</div>
              <div class="watchlist-note">Reusable legacy content blocks</div>
            </div>
            <span class="watchlist-value good">{{ $blocksCount }}</span>
          </div>
        </div>
      </aside>
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Export template</div>
          <h2 class="ledger-title">DOCX template</h2>
          <p class="ledger-copy">Upload the fallback DOCX template used when HTML-aligned export is unavailable.</p>
        </div>
      </div>

      <div class="stack-grid-2 mt-5">
        <div class="surface-note">
          <strong class="text-slate-900">Current configuration</strong>
          <div class="mt-2 text-sm text-slate-600">Export mode: {{ strtoupper($exportEngine ?? 'auto') }}</div>
          <div class="mt-1 text-sm text-slate-600">Template: {{ $templateExists ? 'Installed' : 'Missing' }}</div>
        </div>
        <form method="POST" action="{{ route('aop.syllabi.template.upload') }}" enctype="multipart/form-data" class="surface-note">
          @csrf
          <label for="template">Upload template</label>
          <input id="template" type="file" name="template" accept=".docx" required>
          <div class="mt-4 actions">
            <button class="btn" type="submit">Upload Template</button>
          </div>
          @error('template')
            <div class="mt-3 text-sm text-red-700">{{ $message }}</div>
          @enderror
        </form>
      </div>
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Structure builder</div>
          <h2 class="ledger-title">Ordered syllabus sections</h2>
          <p class="ledger-copy">Manage the shared structure used below the fixed top portion of each syllabus.</p>
        </div>
        <div class="actions">
          <a class="btn" href="{{ route('aop.syllabi.structure.create') }}">New Structure Section</a>
        </div>
      </div>

      @if(($definitions ?? collect())->count() === 0)
        <p class="mt-5 muted">No structure sections have been created yet.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Section</th>
                <th>Scope</th>
                <th>Order</th>
                <th>Default Content</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($definitions as $definition)
                <tr>
                  <td>
                    <div class="font-semibold text-slate-900">{{ $definition->title }}</div>
                    <div class="muted">Slug: {{ $definition->slug }}</div>
                    @if($definition->category)
                      <div class="muted">Category: {{ $definition->category }}</div>
                    @endif
                    @if($definition->description)
                      <div class="mt-2 text-sm text-slate-600">{{ $definition->description }}</div>
                    @endif
                  </td>
                  <td>
                    <span class="badge">{{ $definition->scope === 'syllabus' ? 'Per-Syllabus' : 'Global' }}</span>
                  </td>
                  <td>{{ $definition->sort_order }}</td>
                  <td>
                    <div class="markdown-body markdown-preview compact">{!! $definition->content_rendered !!}</div>
                    <div class="mt-2 text-sm text-slate-500">{{ $definition->content_preview_text }}</div>
                  </td>
                  <td>
                    <div class="stack-grid">
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
                    <div class="actions">
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.structure.edit', $definition) }}">Edit</a>
                      @if(!$definition->is_locked)
                        <form method="POST" action="{{ route('aop.syllabi.structure.destroy', $definition) }}" onsubmit="return confirm('Delete this syllabus structure section?');">
                          @csrf
                          @method('DELETE')
                          <button class="btn secondary sm" type="submit">Delete</button>
                        </form>
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
          <div class="briefing-kicker">Shared blocks</div>
          <h2 class="ledger-title">Legacy content blocks</h2>
          <p class="ledger-copy">Reusable shared blocks that still appear in syllabus packets and exports.</p>
        </div>
        <div class="actions">
          <a class="btn" href="{{ route('aop.syllabi.blocks.create') }}">New Block</a>
        </div>
      </div>

      @if(($blocks ?? collect())->count() === 0)
        <p class="mt-5 muted">No shared syllabus blocks have been created yet.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Block</th>
                <th>Category</th>
                <th>Content Preview</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($blocks as $block)
                <tr>
                  <td>
                    <div class="font-semibold text-slate-900">{{ $block->title }}</div>
                    @if($block->version)
                      <div class="muted">Version: {{ $block->version }}</div>
                    @endif
                    <div class="muted">Updated {{ $block->updated_at?->format('Y-m-d H:i') }}</div>
                  </td>
                  <td>{{ $block->category ?: '—' }}</td>
                  <td>
                    <div class="markdown-body markdown-preview compact">{!! $block->content_rendered !!}</div>
                    <div class="mt-2 text-sm text-slate-500">{{ $block->content_preview_text }}</div>
                  </td>
                  <td>
                    @if($block->is_locked)
                      <span class="badge warn">Protected</span>
                    @else
                      <span class="badge muted">Editable</span>
                    @endif
                  </td>
                  <td>
                    <div class="actions">
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.blocks.edit', $block) }}">Edit</a>
                      @if(!$block->is_locked)
                        <form method="POST" action="{{ route('aop.syllabi.blocks.destroy', $block) }}" onsubmit="return confirm('Delete this syllabus block?');">
                          @csrf
                          @method('DELETE')
                          <button class="btn secondary sm" type="submit">Delete</button>
                        </form>
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
          <div class="briefing-kicker">Section outputs</div>
          <h2 class="ledger-title">Sections in the active term</h2>
          <p class="ledger-copy">Open a syllabus, update section content, and export files for each section.</p>
        </div>
      </div>

      @if(!$term)
        <p class="mt-5 muted">Set an active term to generate syllabi.</p>
      @elseif($sections->count() === 0)
        <p class="mt-5 muted">No sections found for the active term.</p>
      @else
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Course</th>
                <th>Section</th>
                <th>Instructor</th>
                <th>Outputs</th>
              </tr>
            </thead>
            <tbody>
              @foreach($sections as $s)
                <tr>
                  <td>
                    <div class="font-semibold text-slate-900">{{ $s->offering->catalogCourse->code }}</div>
                    <div class="muted">{{ $s->offering->catalogCourse->title }}</div>
                  </td>
                  <td>
                    <span class="badge">{{ $s->section_code }}</span>
                    <div class="muted">{{ $s->modality }}</div>
                  </td>
                  <td>
                    <div class="text-slate-900">{{ $s->instructor?->name ?? 'TBD' }}</div>
                    <div class="muted">{{ $s->instructor?->email ?? '' }}</div>
                  </td>
                  <td>
                    <div class="actions">
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.show', $s) }}">Open</a>
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.core.edit', $s) }}">Core Content</a>
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.downloadHtml', $s) }}">HTML</a>
                      <a class="btn secondary sm" href="{{ route('aop.syllabi.downloadJson', $s) }}">JSON</a>
                      <a class="btn sm" href="{{ route('aop.syllabi.downloadDocx', $s) }}">DOCX</a>
                      <a class="btn sm" href="{{ route('aop.syllabi.downloadPdf', $s) }}">PDF</a>
                    </div>

                    @php
                      $renderMap = $latestBySection ?? [];
                      $docxRender = $renderMap[$s->id . ':docx'] ?? null;
                      $pdfRender = $renderMap[$s->id . ':pdf'] ?? null;
                      $docxAt = ($docxRender?->completed_at ?? $docxRender?->created_at);
                      $pdfAt = ($pdfRender?->completed_at ?? $pdfRender?->created_at);
                    @endphp
                    <div class="mt-3 text-sm text-slate-500">
                      <div>Last DOCX: {{ $docxAt?->format('Y-m-d H:i') ?? '—' }}</div>
                      <div>Last PDF: {{ $pdfAt?->format('Y-m-d H:i') ?? '—' }}</div>
                    </div>
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
