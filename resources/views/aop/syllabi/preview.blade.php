<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ trim(($packet['course']['code'] ?? '') . ' ' . ($packet['course']['title'] ?? 'Syllabus')) }}</title>
  <style>
    :root {
      --bg: #eef2f7;
      --paper: #ffffff;
      --ink: #111827;
      --muted: #6b7280;
      --border: #dbe3ee;
      --soft: #f8fafc;
      --accent: #111827;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font-family: Arial, Helvetica, sans-serif;
      line-height: 1.5;
    }
    .document-shell {
      max-width: 1020px;
      margin: 0 auto;
      padding: 24px;
    }
    .document {
      background: var(--paper);
      border: 1px solid var(--border);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
    }
    .header {
      padding: 28px 30px 20px;
      border-bottom: 3px solid var(--accent);
    }
    .header-kicker {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .14em;
      color: var(--muted);
      font-weight: 700;
    }
    .header h1 {
      font-size: 28px;
      line-height: 1.2;
      margin: 10px 0 6px;
    }
    .header-subtitle {
      color: #374151;
      font-size: 14px;
    }
    .meta {
      padding: 22px 30px 10px;
    }
    .meta table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .meta th,
    .meta td {
      border: 1px solid var(--border);
      padding: 10px 12px;
      vertical-align: top;
      text-align: left;
    }
    .meta th {
      width: 18%;
      background: var(--soft);
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .meta td {
      width: 32%;
      font-size: 14px;
    }
    .line-list > div + div {
      margin-top: 6px;
    }
    .minor {
      color: var(--muted);
      font-size: 12px;
      margin-top: 2px;
    }
    .section {
      padding: 0 30px 22px;
    }
    .section h2 {
      margin: 18px 0 10px;
      font-size: 15px;
      text-transform: uppercase;
      letter-spacing: .06em;
    }
    .body-copy {
      white-space: pre-wrap;
      color: #1f2937;
      font-size: 14px;
    }
    .body-copy.muted-empty {
      color: var(--muted);
      font-style: italic;
    }
    .note-panel {
      border: 1px solid var(--border);
      background: var(--soft);
      border-radius: 12px;
      padding: 12px 14px;
      margin-top: 10px;
    }
    .note-panel strong {
      display: block;
      margin-bottom: 4px;
    }
    .markdown-body {
      color: #1f2937;
      font-size: 14px;
      line-height: 1.65;
    }
    .markdown-body > :first-child { margin-top: 0; }
    .markdown-body > :last-child { margin-bottom: 0; }
    .markdown-body p { margin: 0 0 10px; }
    .markdown-body ul,
    .markdown-body ol { margin: 0 0 10px; padding-left: 22px; }
    .markdown-body li + li { margin-top: 4px; }
    .markdown-body blockquote {
      margin: 10px 0;
      padding: 8px 12px;
      border-left: 4px solid var(--border);
      background: var(--soft);
      border-radius: 10px;
    }
    .markdown-body code {
      background: #f3f4f6;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      padding: 2px 6px;
      font-size: 13px;
    }
    .markdown-body pre {
      background: #0f172a;
      color: #e5e7eb;
      border-radius: 12px;
      padding: 12px;
      overflow: auto;
    }
    .markdown-body pre code {
      background: transparent;
      border: 0;
      color: inherit;
      padding: 0;
    }
    .block {
      border-top: 1px solid var(--border);
      padding-top: 14px;
      margin-top: 14px;
    }
    .block:first-of-type {
      margin-top: 0;
    }
    .block h3 {
      margin: 0 0 4px;
      font-size: 18px;
    }
    .block-meta {
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 8px;
    }
    .footer {
      padding: 0 30px 28px;
      color: var(--muted);
      font-size: 12px;
    }
    @media print {
      body {
        background: #fff;
      }
      .document-shell {
        max-width: none;
        padding: 0;
      }
      .document {
        border: 0;
        border-radius: 0;
        box-shadow: none;
      }
      .header,
      .meta,
      .section,
      .footer {
        padding-left: 0;
        padding-right: 0;
      }
    }
  </style>
</head>
<body>
  <div class="document-shell">
    <article class="document">
      <header class="header">
        <div class="header-kicker">{{ $departmentLine }}</div>
        <h1>{{ ($packet['course']['code'] ?? 'COURSE') }} — {{ ($packet['course']['title'] ?? 'Untitled Course') }}</h1>
        <div class="header-subtitle">
          Section {{ $packet['section']['code'] ?? 'TBD' }}
          • {{ $termLine }}
          • Generated {{ $generatedDate }}
        </div>
      </header>

      <section class="meta">
        <table>
          <tbody>
            <tr>
              <th>Course Title</th>
              <td>{{ $replacements['COURSE_TITLE'] ?: 'TBD' }}</td>
              <th>Term</th>
              <td>{{ $termLine }}</td>
            </tr>
            <tr>
              <th>Instructor</th>
              <td>{{ $replacements['INSTRUCTOR_NAME'] ?: 'TBD' }}</td>
              <th>Email</th>
              <td>{{ $replacements['INSTRUCTOR_EMAIL'] ?: 'TBD' }}</td>
            </tr>
            <tr>
              <th>Office Hours</th>
              <td colspan="3">
                <div class="line-list">
                  @forelse($officeHourRows as $row)
                    <div>{{ $row['summary'] }}</div>
                  @empty
                    <div>TBD</div>
                  @endforelse
                </div>
              </td>
            </tr>
            <tr>
              <th>Delivery</th>
              <td>{{ $replacements['DELIVERY_MODE'] ?: 'TBD' }}</td>
              <th>Credit Hours</th>
              <td>{{ $replacements['CREDIT_HOURS'] ?: 'TBD' }}</td>
            </tr>
            <tr>
              <th>Location</th>
              <td>
                <div class="line-list">
                  @foreach($locationLines as $line)
                    <div>{{ $line }}</div>
                  @endforeach
                </div>
              </td>
              <th>Days/Times</th>
              <td>
                <div class="line-list">
                  @forelse($meetingRows as $row)
                    <div>
                      <strong>{{ $row['type'] }}</strong>: {{ $row['days_times_line'] }}
                      @if($row['notes'])
                        <div class="minor">{{ $row['notes'] }}</div>
                      @endif
                    </div>
                  @empty
                    <div>TBD</div>
                  @endforelse
                </div>
              </td>
            </tr>
            <tr>
              <th>Prereq</th>
              <td>{{ $replacements['PREREQUISITES'] ?: 'none' }}</td>
              <th>Co req</th>
              <td>{{ $replacements['COREQUISITES'] ?: 'none' }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="section">
        <h2>Course Description</h2>
        <div class="body-copy {{ $replacements['COURSE_DESCRIPTION'] === 'TBD' ? 'muted-empty' : '' }}">{{ $replacements['COURSE_DESCRIPTION'] }}</div>
      </section>

      <section class="section">
        <h2>Course Objectives</h2>
        <div class="body-copy {{ $replacements['COURSE_OBJECTIVES'] === 'TBD' ? 'muted-empty' : '' }}">{{ $replacements['COURSE_OBJECTIVES'] }}</div>
      </section>

      <section class="section">
        <h2>Required Materials</h2>
        <div class="body-copy {{ $replacements['REQUIRED_MATERIALS'] === 'TBD' ? 'muted-empty' : '' }}">{{ $replacements['REQUIRED_MATERIALS'] }}</div>

        @if($replacements['COURSE_NOTES'] !== '' || $replacements['SECTION_NOTES'] !== '')
          <div class="note-panel">
            @if($replacements['COURSE_NOTES'] !== '')
              <strong>Catalog Notes</strong>
              <div class="body-copy">{{ $replacements['COURSE_NOTES'] }}</div>
            @endif

            @if($replacements['SECTION_NOTES'] !== '')
              <strong style="margin-top:{{ $replacements['COURSE_NOTES'] !== '' ? '12px' : '0' }};">Section Notes</strong>
              <div class="body-copy">{{ $replacements['SECTION_NOTES'] }}</div>
            @endif
          </div>
        @endif
      </section>

      @if(($packet['blocks'] ?? []) !== [])
        <section class="section">
          <h2>Shared Syllabus Blocks</h2>
          @foreach(($packet['blocks'] ?? []) as $block)
            <div class="block">
              <h3>{{ $block['title'] ?: 'Untitled Block' }}</h3>
              <div class="block-meta">
                {{ $block['category'] ?: 'Uncategorized' }}
                @if(!empty($block['version']))
                  • Version {{ $block['version'] }}
                @endif
                @if(!empty($block['is_locked']))
                  • Protected
                @endif
              </div>
              <div class="markdown-body">{!! $block['content_rendered'] ?? '<p>—</p>' !!}</div>
            </div>
          @endforeach
        </section>
      @endif

      <div class="footer">
        Browser preview uses the same syllabus packet as the JSON export. DOCX and PDF formatting come from the uploaded DOCX template, with PDF generated from the rendered DOCX.
      </div>
    </article>
  </div>
</body>
</html>
