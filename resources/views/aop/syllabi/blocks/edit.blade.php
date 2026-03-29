<x-aop-layout>
  <x-slot:title>Edit Syllabus Block</x-slot:title>

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Shared blocks</div>
        <h1 class="briefing-title">Edit {{ $block->title }}.</h1>
        <p class="briefing-copy">Update the shared block content used in syllabus previews and export packets.</p>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Current status</div>
        <h2 class="watchlist-title">Block settings</h2>
        <p class="watchlist-copy">Review versioning and protection before saving changes.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Category</div>
              <div class="watchlist-note">Grouping for shared content</div>
            </div>
            <span class="watchlist-value good">{{ $block->category ?: 'None' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Version</div>
              <div class="watchlist-note">Current version label</div>
            </div>
            <span class="watchlist-value good">{{ $block->version ?: 'None' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Protection</div>
              <div class="watchlist-note">Deletion lock</div>
            </div>
            <span class="watchlist-value {{ $block->is_locked ? 'warn' : 'good' }}">{{ $block->is_locked ? 'Protected' : 'Editable' }}</span>
          </div>
        </div>
      </aside>
    </section>

    @include('aop.syllabi.blocks._form', [
      'action' => route('aop.syllabi.blocks.update', $block),
      'method' => 'PUT',
      'submitLabel' => 'Save Changes',
      'block' => $block,
    ])
  </div>
</x-aop-layout>
