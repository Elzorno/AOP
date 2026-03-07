<x-aop-layout>
  <x-slot:title>Instructor Grid</x-slot:title>

  <style>
    .sched-grid {
      --slot-height: 42px;
      --slot-border: 1px;
      width:100%;
      border-collapse:collapse;
      table-layout:fixed;
    }
    .sched-grid th, .sched-grid td { border:1px solid var(--border); padding:8px; }
    .sched-grid th { background:#fafafa; position:sticky; top:0; z-index:2; }
    .time-col { width:84px; background:#fafafa; position:sticky; left:0; z-index:1; }
    .slot { height:var(--slot-height); }
    .slot--filled { padding:4px !important; vertical-align:top; }
    .slot-stack {
      position:relative;
      height:var(--slot-fill-height);
      min-height:var(--slot-fill-height);
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .slot-stack--single {
      display:block;
    }
    .event {
      border:1px solid var(--event-border, var(--border));
      border-radius:10px;
      padding:6px 8px;
      margin:0;
      background:var(--event-bg, white);
      box-shadow: inset 4px 0 0 var(--event-accent, transparent);
      display:flex;
      flex:1 1 0;
      min-height:0;
      flex-direction:column;
      justify-content:space-between;
    }
    .event--single {
      position:absolute;
      left:0;
      right:0;
      top:var(--event-top, 0px);
      height:var(--event-height, 100%);
    }
    .event small { display:block; color:var(--muted); margin-top:6px; }
    .event.office { background:#f8fafc; box-shadow:none; border-color:var(--border); }
    .event.class { background:var(--event-bg, #ffffff); }
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
      <h1>Instructor Grid</h1>
      <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}<span class="muted"> • </span><strong>{{ $instructor->name }}</strong></p>
      <p class="muted">Includes classes + office hours. Window auto-fits scheduled blocks.</p>
    </div>
    @if(!$isPrint)
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Back to Grids</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.show', $instructor) }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.grids.instructor', $instructor) }}?print=1" target="_blank">Print</a>
      </div>
    @endif
  </div>

  <div class="card" style="overflow:auto;">
    @php
      $slots = $grid['slots'];
      $slotMinutes = $grid['slot_minutes'];
      $slotHeightPx = 42;
      $slotBorderPx = 1;
      $baseCellPadYPx = 8;
      $filledCellPadYPx = 4;
      [$sh,$sm] = array_map('intval', explode(':', $start));
      $startTotal = $sh*60 + $sm;

      $fmt = function(int $minutes) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
      };

      $timeToMinutes = function(string $hhmm) {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($h * 60) + $m;
      };

      $fullCellHeightPx = function(int $rowspan) use ($slotHeightPx, $slotBorderPx, $baseCellPadYPx, $filledCellPadYPx) {
        return max(
          1,
          (($slotHeightPx + ($baseCellPadYPx * 2)) * $rowspan)
            + ($slotBorderPx * max(0, $rowspan - 1))
            - ($filledCellPadYPx * 2)
        );
      };

      $singleEventLayout = function(array $event, string $cellStart, int $rowspan) use ($timeToMinutes, $slotMinutes, $fullCellHeightPx) {
        $reservedMinutes = max($slotMinutes, $rowspan * $slotMinutes);
        $eventStart = $timeToMinutes($event['starts_at']);
        $eventEnd = $timeToMinutes($event['ends_at']);
        $cellStartMinutes = $timeToMinutes($cellStart);

        $offsetMinutes = max(0, min($reservedMinutes, $eventStart - $cellStartMinutes));
        $durationMinutes = max(1, $eventEnd - $eventStart);
        $durationMinutes = min($durationMinutes, max(1, $reservedMinutes - $offsetMinutes));

        $fullHeightPx = $fullCellHeightPx($rowspan);
        $topPx = $fullHeightPx * ($offsetMinutes / $reservedMinutes);
        $heightPx = max(24, $fullHeightPx * ($durationMinutes / $reservedMinutes));
        $heightPx = min($fullHeightPx - $topPx, $heightPx);

        return [
          'stack_height' => sprintf('%.3Fpx', $fullHeightPx),
          'top' => sprintf('%.3Fpx', $topPx),
          'height' => sprintf('%.3Fpx', $heightPx),
        ];
      };

      $dayLabel = function(string $d) {
        return $d;
      };
    @endphp

    @if ($slots <= 0)
      <p>No schedule data for this instructor in the active term.</p>
    @else
      <table class="sched-grid">
        <thead>
          <tr>
            <th class="time-col">Time</th>
            @foreach ($days as $d)
              <th>{{ $dayLabel($d) }}</th>
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
                  @php
                    $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
                    $eventsInCell = $cell['events'] ?? [];
                    $isSingleEvent = count($eventsInCell) === 1;
                    $singleLayout = $isSingleEvent ? $singleEventLayout($eventsInCell[0], $rowTime, $rowspan) : null;
                    $fillHeight = $isSingleEvent
                      ? $singleLayout['stack_height']
                      : sprintf('%.3Fpx', $fullCellHeightPx($rowspan));
                  @endphp
                  <td class="slot slot--filled" rowspan="{{ $rowspan }}">
                    <div class="slot-stack @if($isSingleEvent) slot-stack--single @endif" style="--slot-fill-height: {{ $fillHeight }};">
                      @foreach ($eventsInCell as $ev)
                        @php
                          $klass = $ev['kind'] === 'office' ? 'office' : 'class';
                          $timeRange = $ev['starts_at'] . '–' . $ev['ends_at'];
                          $styleParts = [];

                          if ($klass === 'class') {
                            if (!empty($ev['style']['accent'])) {
                              $styleParts[] = '--event-accent:' . $ev['style']['accent'];
                            }
                            if (!empty($ev['style']['bg'])) {
                              $styleParts[] = '--event-bg:' . $ev['style']['bg'];
                            }
                            if (!empty($ev['style']['border'])) {
                              $styleParts[] = '--event-border:' . $ev['style']['border'];
                            }
                          }

                          if ($isSingleEvent && $singleLayout) {
                            $styleParts[] = '--event-top:' . $singleLayout['top'];
                            $styleParts[] = '--event-height:' . $singleLayout['height'];
                          }

                          $inlineStyle = implode(';', $styleParts);
                        @endphp
                        <div class="event {{ $klass }} @if($isSingleEvent) event--single @endif" @if($inlineStyle !== '') style="{{ $inlineStyle }}" @endif>
                          <div style="font-weight:600;">{{ $ev['label'] }}</div>
                          <small>{{ $timeRange }}</small>
                        </div>
                      @endforeach
                    </div>
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
