<x-aop-layout :activeTermLabel="'Active Term: '.$term->code.' — '.$term->name">
  <x-slot:title>Calendar View</x-slot:title>

  <style>
    #calendar {
      max-width: 100%;
      margin: 0 auto;
      background: white;
      padding: 15px;
      border-radius: 6px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    #calendarStatus {
      display: none;
      margin: 0 0 14px 0;
      padding: 10px 12px;
      border-radius: 6px;
      border-left: 4px solid transparent;
      background: #f8fafc;
    }

    #calendarStatus.is-error {
      display: block;
      border-left-color: #dc2626;
      color: #991b1b;
      background: #fef2f2;
    }

    #calendarStatus.is-success {
      display: block;
      border-left-color: #16a34a;
      color: #166534;
      background: #f0fdf4;
    }
  </style>

  <div class="row" style="margin-bottom:14px;">
    <h2>Calendar View for {{ $term->code }}</h2>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
    </div>
  </div>

  <p class="muted" style="margin-bottom:20px;">
    Drag and drop blocks to update their times. Note: This grid maps Monday-Sunday to an arbitrary week so you can visualize the weekly schedule. Ensure that block starts and ends within the day.
  </p>

  <div id="calendarStatus" role="status" aria-live="polite"></div>

  <div id="calendar"></div>

  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@6.1.15/index.global.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var calendarEl = document.getElementById('calendar');
      var statusEl = document.getElementById('calendarStatus');

      function setStatus(message, level) {
        statusEl.textContent = message;
        statusEl.classList.remove('is-error', 'is-success');
        statusEl.classList.add(level === 'success' ? 'is-success' : 'is-error');
      }

      async function submitCalendarUpdate(info) {
        var start = info.event.start;
        var end = info.event.end;

        if (!start || !end) {
          info.revert();
          setStatus('Unable to update: event time could not be read.', 'error');
          return;
        }

        var startStr = start.toTimeString().substring(0, 5);
        var endStr = end.toTimeString().substring(0, 5);
        var blockId = info.event.extendedProps.blockId;

        try {
          var response = await fetch("{{ route('aop.schedule.calendar.update') }}", {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': '{{ csrf_token() }}',
              'Accept': 'application/json',
            },
            body: JSON.stringify({
              blockId: blockId,
              starts_at: startStr,
              ends_at: endStr
            })
          });

          var data = await response.json();

          if (!response.ok || data.status !== 'success') {
            info.revert();
            setStatus(data.message || 'Update failed. Changes were reverted.', 'error');
            return;
          }

          setStatus(data.message || 'Meeting block updated.', 'success');
        } catch (error) {
          console.error('Error:', error);
          info.revert();
          setStatus('An unexpected error occurred. Changes were reverted.', 'error');
        }
      }
      
      var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        initialDate: '2026-01-05', /* Matches the arbitrary week in Controller */
        headerToolbar: false,
        allDaySlot: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '22:00:00',
        height: 'auto',
        dayHeaderFormat: { weekday: 'long' },
        editable: true,
        droppable: true,
        eventOverlap: true,
        events: "{{ route('aop.schedule.calendar.events') }}",
        eventDrop: function(info) {
          submitCalendarUpdate(info);
        },
        eventResize: function(info) {
            submitCalendarUpdate(info);
        }
      });
      
      calendar.render();
    });
  </script>
</x-aop-layout>
