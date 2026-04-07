<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Schedule Calendar</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Weekly Grid</span>
      <h1 class="page-title">Schedule — {{ $term->code }}</h1>
      <p class="page-subtitle">Drag blocks up or down to adjust meeting times. Day columns are fixed — dragging between days is disabled.</p>

      <div class="toolbar-line">
        <a class="btn" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
        <a class="btn secondary" href="{{ route('aop.schedule.sections.index') }}">Section Directory</a>
      </div>
    </section>

    <div id="calendarStatus" class="mb-4 hidden rounded-2xl border px-4 py-3 text-sm font-medium" role="status" aria-live="polite"></div>

    <div id="calendar" class="rounded-2xl border border-slate-200 bg-white shadow-sm"></div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@6.1.15/index.global.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const calendarEl = document.getElementById('calendar');
      const statusEl   = document.getElementById('calendarStatus');

      // ── Events embedded directly from the server (no AJAX fetch) ────────
      const EVENTS = {!! $eventsJson !!};

      // ── Status helpers ───────────────────────────────────────────────────
      function showStatus(message, level) {
        statusEl.textContent = message;
        statusEl.className   = 'mb-4 rounded-2xl border px-4 py-3 text-sm font-medium ' + (
          level === 'success'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : 'border-red-200 bg-red-50 text-red-800'
        );
      }
      function hideStatus() {
        statusEl.className = 'mb-4 hidden rounded-2xl border px-4 py-3 text-sm font-medium';
      }

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

        try {
          const res  = await fetch('{{ route('aop.schedule.calendar.update') }}', {
            method:  'POST',
            headers: {
              'Content-Type':  'application/json',
              'X-CSRF-TOKEN':  '{{ csrf_token() }}',
              'Accept':        'application/json',
            },
            body: JSON.stringify({ blockId, starts_at: startStr, ends_at: endStr }),
          });

          const data = await res.json();

          if (!res.ok || data.status !== 'success') {
            info.revert();
            showStatus(data.message || 'Update failed — change reverted.', 'error');
            return;
          }

          showStatus('Time updated: ' + startStr + ' – ' + endStr, 'success');
          setTimeout(hideStatus, 4000);

        } catch (err) {
          info.revert();
          showStatus('Unexpected error — change reverted.', 'error');
        }
      }

      // ── Calendar ─────────────────────────────────────────────────────────
      const calendar = new FullCalendar.Calendar(calendarEl, {
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
