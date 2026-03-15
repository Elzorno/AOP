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

  <div id="calendar"></div>

  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@6.1.15/index.global.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var calendarEl = document.getElementById('calendar');
      
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
          // Send AJAX request to update block time
          var start = info.event.start;
          var end = info.event.end;
          
          if (!start || !end) {
              info.revert();
              return;
          }

          var startStr = start.toTimeString().substring(0, 5);
          var endStr = end.toTimeString().substring(0, 5);
          var blockId = info.event.extendedProps.blockId;

          fetch("{{ route('aop.schedule.calendar.update') }}", {
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
          })
          .then(response => response.json())
          .then(data => {
            if (data.status !== 'success') {
              alert('Failed to update time.');
              info.revert();
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('An error occurred.');
            info.revert();
          });
        },
        eventResize: function(info) {
            // we can reuse the same update endpoint for scaling the duration
            var start = info.event.start;
            var end = info.event.end;
            
            if (!start || !end) {
                info.revert();
                return;
            }

            var startStr = start.toTimeString().substring(0, 5);
            var endStr = end.toTimeString().substring(0, 5);
            var blockId = info.event.extendedProps.blockId;

            fetch("{{ route('aop.schedule.calendar.update') }}", {
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
            })
            .then(response => response.json())
            .then(data => {
              if (data.status !== 'success') {
                alert('Failed to update duration.');
                info.revert();
              }
            })
            .catch(error => {
              console.error('Error:', error);
              alert('An error occurred.');
              info.revert();
            });
        }
      });
      
      calendar.render();
    });
  </script>
</x-aop-layout>
