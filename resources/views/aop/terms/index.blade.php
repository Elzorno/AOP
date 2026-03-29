<x-aop-layout :activeTermLabel="$active ? 'Active Term: '.$active->code.' - '.$active->name : 'No active term selected'">
  <x-slot:title>Terms</x-slot:title>

  @php
    $draftCount = $terms->where('status', 'draft')->count();
    $publishedCount = $terms->where('status', 'published')->count();
  @endphp

  <div class="page-shell">
    <section class="briefing-grid">
      <div class="briefing-panel briefing-panel-strong">
        <div class="briefing-kicker">Term control</div>
        <h1 class="briefing-title">{{ $active ? $active->code.' is active' : 'Set the active term' }}</h1>
        <p class="briefing-copy">Manage active, draft, and published terms.</p>

        <div class="status-ribbon">
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $active ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
            {{ $active ? $active->code.' is active' : 'No active term selected' }}
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot bg-blue-500"></span>
            {{ $terms->count() }} terms tracked
          </span>
          <span class="status-ribbon-item">
            <span class="status-ribbon-dot {{ $draftCount > 0 ? 'bg-slate-500' : 'bg-slate-300' }}"></span>
            {{ $draftCount }} drafts
          </span>
        </div>

        <div class="mt-8 quick-actions">
          <a class="btn" href="{{ route('aop.terms.create') }}">Create New Term</a>
          @if ($active)
            <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Open Schedule</a>
          @endif
        </div>
      </div>

      <aside class="briefing-sidebar">
        <div class="briefing-kicker">Status</div>
        <h2 class="watchlist-title">Current term status</h2>
        <p class="watchlist-copy">Active, draft, and published counts.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Active term</div>
              <div class="watchlist-note">Used across schedule pages</div>
            </div>
            <span class="watchlist-value {{ $active ? 'good' : 'warn' }}">{{ $active ? $active->code : 'Unset' }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Draft terms</div>
              <div class="watchlist-note">Pending schedule work</div>
            </div>
            <span class="watchlist-value good">{{ $draftCount }}</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Published terms</div>
              <div class="watchlist-note">Released lifecycle states</div>
            </div>
            <span class="watchlist-value good">{{ $publishedCount }}</span>
          </div>
        </div>
      </aside>
    </section>

    <section class="dashboard-grid">
      <div class="lg:col-span-7 sequence-board">
        <article class="sequence-item">
          <div class="sequence-head">
            <div class="flex gap-4">
              <div class="sequence-index">1</div>
              <div>
                <div class="sequence-label">Primary action</div>
                <h2 class="sequence-title">Set the active term.</h2>
                <p class="sequence-copy">Schedule pages follow this term automatically.</p>
              </div>
            </div>
          </div>

          <form method="POST" action="{{ route('aop.terms.setActive') }}" class="mt-6">
            @csrf
            <label for="term_id">Active term</label>
            <div class="mt-2 flex flex-col gap-3 md:flex-row">
              <select name="term_id" id="term_id" required class="md:flex-1">
                <option value="" disabled {{ $active ? '' : 'selected' }}>Choose a term</option>
                @foreach ($terms as $t)
                  <option value="{{ $t->id }}" {{ $active && $active->id === $t->id ? 'selected' : '' }}>
                    {{ $t->code }} - {{ $t->name }}
                  </option>
                @endforeach
              </select>
              <button class="btn md:self-end" type="submit">Set Active Term</button>
            </div>
          </form>
        </article>

        <article class="sequence-item">
          <div class="sequence-head">
            <div class="flex gap-4">
              <div class="sequence-index">2</div>
              <div>
                <div class="sequence-label">Fast cloning path</div>
                <h2 class="sequence-title">Clone an existing term into a draft.</h2>
                <p class="sequence-copy">Reuse an earlier setup for the next cycle.</p>
              </div>
            </div>
          </div>
          <div class="sequence-strip">
            <div class="sequence-chip"><strong>Copies structure</strong> Offerings, sections, and meeting blocks come forward.</div>
            <div class="sequence-chip"><strong>Optional instructors</strong> Instructor assignments are not forced into the next cycle.</div>
            <div class="sequence-chip"><strong>Skips fragile history</strong> Locks, publications, office hours, syllabi, and render history do not carry over.</div>
          </div>
        </article>
      </div>

      <aside class="lg:col-span-5 watchlist">
        <div class="briefing-kicker">Notes</div>
        <h2 class="watchlist-title">Term actions</h2>
        <p class="watchlist-copy">Activation changes the default term. Cloning creates a new draft.</p>

        <div class="watchlist-group">
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Activation effect</div>
              <div class="watchlist-note">Schedule pages follow this term automatically</div>
            </div>
            <span class="watchlist-value good">Global</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Clone behavior</div>
              <div class="watchlist-note">Build a draft from an existing template</div>
            </div>
            <span class="watchlist-value good">Safe</span>
          </div>
          <div class="watchlist-item">
            <div>
              <div class="watchlist-name">Publish behavior</div>
              <div class="watchlist-note">Only available from draft terms</div>
            </div>
            <span class="watchlist-value good">Controlled</span>
          </div>
        </div>
      </aside>
    </section>

    <section class="ledger-shell">
      <div class="ledger-header">
        <div>
          <div class="briefing-kicker">Term ledger</div>
          <h2 class="ledger-title">All terms</h2>
          <p class="ledger-copy">Dates, settings, status, and actions.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Dates</th>
              <th>Weeks</th>
              <th>Slot</th>
              <th>Buffer</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($terms as $t)
              <tr>
                <td>
                  <div class="font-semibold text-slate-900">{{ $t->code }}</div>
                  @if ($active && $active->id === $t->id)
                    <span class="badge mt-2">Active</span>
                  @endif
                </td>
                <td>
                  <div class="font-medium text-slate-900">{{ $t->name }}</div>
                  @if ($t->status)
                    <span class="badge muted mt-2">{{ ucfirst($t->status) }}</span>
                  @endif
                </td>
                <td>{{ $t->starts_on?->format('Y-m-d') ?? '—' }} to {{ $t->ends_on?->format('Y-m-d') ?? '—' }}</td>
                <td>{{ $t->weeks_in_term }}</td>
                <td>{{ $t->slot_minutes }}m</td>
                <td>{{ $t->buffer_minutes }}m</td>
                <td>
                  <div class="actions">
                    <a class="btn secondary sm" href="{{ route('aop.terms.edit', $t) }}">Edit</a>
                    <a class="btn secondary sm" href="{{ route('aop.terms.clone.create', $t) }}">Clone</a>
                    <form method="POST" action="{{ route('aop.terms.draft', $t) }}" class="actions">
                      @csrf
                      <button class="btn secondary sm" type="submit">Clone to Draft</button>
                    </form>
                    @if ($t->status === 'draft')
                      <form method="POST" action="{{ route('aop.terms.publish', $t) }}" class="actions">
                        @csrf
                        <button class="btn success sm" type="submit">Publish</button>
                      </form>
                    @endif
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</x-aop-layout>
