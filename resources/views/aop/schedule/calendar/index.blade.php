<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Schedule Calendar</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Weekly Grid</span>
      <h1 class="page-title">Schedule — {{ $term->code }}</h1>
      <p class="page-subtitle">Drag class blocks up or down to adjust meeting times. To edit without dragging, focus a class meeting and press Enter to open its section editor. Open labs are shown for room visibility but are edited from the Open Labs screen.</p>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home', $term) }}">Back to Schedule</a>
        <a class="btn secondary" href="{{ route('aop.schedule.sections.index', $term) }}">Section Directory</a>
        <a class="btn secondary" href="{{ route('aop.schedule.openLabs.index', $term) }}">Open Labs</a>
      </div>
    </section>

    <div id="calendarStatus" class="mb-4 hidden rounded-2xl border px-4 py-3 text-sm font-medium" role="status" aria-live="polite" tabindex="-1">
      <span id="calendarStatusMessage"></span>
      <button id="calendarReload" type="button" class="btn secondary sm ml-3 hidden">Reload calendar</button>
    </div>

    <div id="calendar" class="rounded-2xl border border-slate-200 bg-white shadow-sm"></div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const calendarEl = document.getElementById('calendar');
      const statusEl   = document.getElementById('calendarStatus');
      const messageEl  = document.getElementById('calendarStatusMessage');
      const reloadEl   = document.getElementById('calendarReload');
      let statusTimer  = null;
      let activeUpdates = 0;
      let calendar;

      // ── Events embedded directly from the server (no AJAX fetch) ────────
      const EVENTS = {!! $eventsJson !!};

      // ── Status helpers ───────────────────────────────────────────────────
      function showStatus(message, level, offerReload = false) {
        clearTimeout(statusTimer);
        messageEl.textContent = message;
        reloadEl.classList.toggle('hidden', !offerReload);
        statusEl.setAttribute('role', level === 'error' ? 'alert' : 'status');
        statusEl.setAttribute('aria-live', level === 'error' ? 'assertive' : 'polite');
        statusEl.className   = 'mb-4 rounded-2xl border px-4 py-3 text-sm font-medium ' + (
          level === 'success'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : level === 'pending'
              ? 'border-sky-200 bg-sky-50 text-sky-800'
              : 'border-red-200 bg-red-50 text-red-800'
        );

        if (level === 'error') statusEl.focus();
      }
      function hideStatus() {
        clearTimeout(statusTimer);
        statusEl.className = 'mb-4 hidden rounded-2xl border px-4 py-3 text-sm font-medium';
        reloadEl.classList.add('hidden');
      }
      reloadEl.addEventListener('click', () => window.location.reload());

      // ── Drag-drop update (time changes only — day never changes) ─────────
      async function submitTimeUpdate(info) {
        const start   = info.event.start;
        const end     = info.event.end;

        if (!start || !end) {
          info.revert();
          showStatus('Could not read new time — change reverted.', 'error');
          return;
        }

        const startStr = start.toTimeString().substring(0, 5);
        const endStr   = end.toTimeString().substring(0, 5);
        const blockId  = info.event.extendedProps.blockId;
        activeUpdates++;
        calendar.setOption('editable', false);
        showStatus('Saving the new time…', 'pending');

        try {
          const res  = await fetch('{{ route('aop.schedule.calendar.update', $term) }}', {
            method:  'POST',
            headers: {
              'Content-Type':  'application/json',
              'X-CSRF-TOKEN':  '{{ csrf_token() }}',
              'Accept':        'application/json',
            },
            body: JSON.stringify({
              blockId,
              starts_at: startStr,
              ends_at: endStr,
              revision: info.event.extendedProps.revision,
            }),
          });

          const data = await res.json().catch(() => ({}));

          if (!res.ok || data.status !== 'success') {
            info.revert();
            const staleRevision = Boolean(data.errors && data.errors.revision);
            showStatus(
              data.message || 'Update failed — change reverted.',
              'error',
              staleRevision,
            );
            return;
          }

          calendar.getEvents()
            .filter(event => event.extendedProps.scheduledEventId === info.event.extendedProps.scheduledEventId)
            .forEach(event => event.setExtendedProp('revision', data.block.revision));

          showStatus('Time updated: ' + startStr + ' – ' + endStr, 'success');
          statusTimer = setTimeout(hideStatus, 4000);

        } catch (err) {
          info.revert();
          showStatus('Unexpected error — change reverted.', 'error');
        } finally {
          activeUpdates = Math.max(0, activeUpdates - 1);
          if (activeUpdates === 0) calendar.setOption('editable', true);
        }
      }

      // ── Calendar ─────────────────────────────────────────────────────────
      calendar = new window.AopCalendar.Calendar(calendarEl, {
        plugins: [window.AopCalendar.timeGridPlugin, window.AopCalendar.interactionPlugin],
        initialView:  'timeGridWeek',
        initialDate:  '2026-01-05',   // Fixed reference week — never shown as a date
        headerToolbar: false,          // No navigation — this is a timeless weekly grid
        allDaySlot:   false,
        weekends:     true,            // Keep Sat/Sun columns in case of weekend sections
        slotMinTime:  '07:00:00',
        slotMaxTime:  '23:00:00',
        slotDuration: '00:30:00',
        height:       'auto',

        // Show only weekday names — no date numbers
        dayHeaderContent: function (arg) {
          return arg.date.toLocaleDateString('en-US', { weekday: 'long' });
        },

        editable:     true,
        droppable:    true,
        eventOverlap: false,

        // Block cross-day drags — only allow time shifts within the same column
        eventAllow: function (dropInfo, draggedEvent) {
          return dropInfo.start.getDay() === draggedEvent.start.getDay();
        },

        events: EVENTS,

        eventDrop:   function (info) { submitTimeUpdate(info); },
        eventResize: function (info) { submitTimeUpdate(info); },
      });

      calendar.render();
    });
  </script>
</x-aop-layout>
