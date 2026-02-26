<x-aop-layout>
  <x-slot:title>Room Grid</x-slot:title>

  <style>
    .sched-grid { width:100%; border-collapse:collapse; table-layout:fixed; }
    .sched-grid th, .sched-grid td { border:1px solid var(--border); padding:8px; }
    .sched-grid th { background:#fafafa; position:sticky; top:0; z-index:2; }
    .time-col { width:84px; background:#fafafa; position:sticky; left:0; z-index:1; }
    .slot { height:42px; }
    .event { border:1px solid var(--border); border-radius:10px; padding:6px 8px; margin:4px 0; background:white; }
    .event small { display:block; color:var(--muted); margin-top:2px; }
    .muted { color:var(--muted); font-size:12px; }

    @media print {
      .actions, .btn, nav, header { display:none !important; }
      .card { border:none !important; box-shadow:none !important; }
      .sched-grid th { position:static; }
      .time-col { position:static; }
      body { background:#fff !important; }
    }
  </style>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Room Grid</h1>
      <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}<span class="muted"> • </span><strong>{{ $room->name }}</strong></p>
      <p class="muted">Includes classes only. Window auto-fits scheduled blocks.</p>
    </div>
    @if(!$isPrint)
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Back to Grids</a>
        <a class="btn" href="{{ route('aop.rooms.index') }}">Rooms</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.grids.room', $room) }}?print=1" target="_blank">Print</a>
      </div>
    @endif
  </div>

  <div class="card" style="overflow:auto;">
    @php
      $slots = $grid['slots'];
      $slotMinutes = $grid['slot_minutes'];
      [$sh,$sm] = array_map('intval', explode(':', $start));
      $startTotal = $sh*60 + $sm;

      $fmt = function(int $minutes) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
      };
    @endphp

    @if ($slots <= 0)
      <p>No schedule data for this room in the active term.</p>
    @else
      <table class="sched-grid">
        <thead>
          <tr>
            <th class="time-col">Time</th>
            @foreach ($days as $d)
              <th>{{ $d }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @for ($i = 0; $i < $slots; $i++)
            @php
              $rowTime = $fmt($startTotal + ($i * $slotMinutes));
            @endphp
            <tr>
              <td class="time-col"><span class="muted">{{ $rowTime }}</span></td>

              @foreach ($days as $d)
                @php $cell = $grid['cells'][$d][$i]; @endphp

                @if (is_array($cell) && ($cell['type'] ?? null) === 'skip')
                  @continue
                @endif

                @if (is_array($cell) && ($cell['type'] ?? null) === 'cell')
                  <td class="slot" rowspan="{{ $cell['rowspan'] }}">
                    @foreach ($cell['events'] as $ev)
                      @php $timeRange = $ev['starts_at'] . '–' . $ev['ends_at']; @endphp
                      <div class="event">
                        <div style="font-weight:600;">{{ $ev['label'] }}</div>
                        <small>{{ $timeRange }}</small>
                      </div>
                    @endforeach
                  </td>
                @else
                  <td class="slot"></td>
                @endif
              @endforeach
            </tr>
          @endfor
        </tbody>
      </table>
    @endif
  </div>

  @if($isPrint)
    <script>window.addEventListener('load', () => { window.print(); });</script>
  @endif
</x-aop-layout>
