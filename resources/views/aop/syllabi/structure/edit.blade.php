<x-aop-layout>
  <x-slot:title>Edit Syllabus Structure Section</x-slot:title>

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Structure builder</div>
        <h1 class="briefing-title">Edit {{ $definition->title }}.</h1>
        <p class="briefing-copy">Update the shared title, scope, default content, and ordering for this structure section.</p>

        <div class="mt-8 quick-actions">
          <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Current status</div>
        <h2 class="watchlist-title">Section settings</h2>
        <p class="watchlist-copy">Review scope, visibility, and protection before saving changes.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Scope</div>
              <div class="watchlist-note">How this section behaves across syllabi</div>
            </div>
            <span class="watchlist-value good">{{ $definition->scope === 'syllabus' ? 'Per-Syllabus' : 'Global' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Status</div>
              <div class="watchlist-note">Whether the section is available</div>
            </div>
            <span class="watchlist-value {{ $definition->is_active ? 'good' : 'warn' }}">{{ $definition->is_active ? 'Active' : 'Inactive' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Protection</div>
              <div class="watchlist-note">Deletion lock</div>
            </div>
            <span class="watchlist-value {{ $definition->is_locked ? 'warn' : 'good' }}">{{ $definition->is_locked ? 'Protected' : 'Editable' }}</span>
          </div>
        </div>
      </aside>
    </section>

    @include('aop.syllabi.structure._definition_form', [
      'action' => route('aop.syllabi.structure.update', $definition),
      'method' => 'PUT',
      'submitLabel' => 'Save Changes',
      'definition' => $definition,
    ])
  </div>
</x-aop-layout>
