<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title>{{ $title ?? 'Academic Ops Platform' }}</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <style>
    /* Markdown Preview specific legacy styling overrides that are hard to shim perfectly with apply */
    .markdown-body { color: rgb(15 23 42); line-height: 1.6; }
    .markdown-body p { margin-bottom: 1rem; }
    .markdown-body ul, .markdown-body ol { padding-left: 22px; list-style-type: disc; margin-bottom: 1rem; }
    .markdown-body blockquote { margin: 10px 0; padding: 12px 16px; border-left: 4px solid #e2e8f0; background: #f8fafc; border-radius: 8px; font-style: italic;}
    .markdown-body pre { background:#0f172a; color:#e5e7eb; padding:16px; border-radius:12px; overflow:auto; font-size: 0.875rem;}
    .markdown-body code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    .markdown-body p code, .markdown-body li code { background:#f1f5f9; border:1px solid #e2e8f0; padding:2px 6px; border-radius:6px; font-size:0.875em; color: #db2777; }
    .markdown-preview.compact { max-height: 150px; overflow: auto; font-size: 0.875rem; }
    .markdown-preview.compact p { margin-bottom: 0.5rem; }
    .toast-editor-shell { margin-top: 0.5rem; }
    .toastui-editor-defaultUI { border-radius: 0.75rem; overflow: hidden; border-color: #cbd5e1 !important; }
    .toastui-editor-defaultUI-toolbar { border-top-left-radius: 0.75rem; border-top-right-radius: 0.75rem; }
  </style>
</head>
<body class="font-sans antialiased text-slate-900 min-h-screen selection:bg-blue-100 selection:text-blue-900">
@php
  $navGroups = [
    [
      'label' => 'Core',
      'items' => [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'match' => 'dashboard'],
        ['route' => 'aop.schedule.home', 'label' => 'Schedule', 'match' => 'aop.schedule.*'],
      ],
    ],
    [
      'label' => 'Setup',
      'items' => [
        ['route' => 'aop.terms.index', 'label' => 'Terms', 'match' => 'aop.terms.*'],
        ['route' => 'aop.catalog.index', 'label' => 'Catalog', 'match' => 'aop.catalog.*'],
        ['route' => 'aop.instructors.index', 'label' => 'Instructors', 'match' => 'aop.instructors.*'],
        ['route' => 'aop.rooms.index', 'label' => 'Rooms', 'match' => 'aop.rooms.*'],
      ],
    ],
  ];

  /* Schedule sub-nav: visible when inside any schedule.* route */
  $scheduleSubNav = [
    ['route' => 'aop.schedule.home',            'label' => 'Studio',    'match' => 'aop.schedule.home'],
    ['route' => 'aop.schedule.readiness.index', 'label' => 'Readiness', 'match' => 'aop.schedule.readiness.*'],
    ['route' => 'aop.schedule.calendar.index',  'label' => 'Calendar',  'match' => 'aop.schedule.calendar.*'],
    ['route' => 'aop.schedule.grids.index',     'label' => 'Grids',     'match' => 'aop.schedule.grids.*'],
    ['route' => 'aop.schedule.publish.index',   'label' => 'Publish',   'match' => 'aop.schedule.publish.*'],
  ];
  $inSchedule = request()->routeIs('aop.schedule.*');
@endphp

<div class="min-h-screen md:flex">
  <aside class="hidden md:flex md:w-[19rem] md:flex-shrink-0 xl:w-[20rem]">
    <div class="sticky top-0 flex h-screen w-full flex-col border-r border-slate-200/70 bg-slate-950 px-6 py-6 text-white shadow-2xl shadow-slate-950/20">
      <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.03] p-5">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Academic Ops</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-white">Operations Hub</h1>
          </div>
          <div class="rounded-full bg-blue-500/20 px-3 py-1 text-xs font-semibold text-blue-200">Admin</div>
        </div>

        <div class="mt-5 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-4">
          <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300">
            <span class="h-2 w-2 rounded-full bg-emerald-300"></span>
            Active term
          </div>
          <p class="mt-2 text-sm leading-6 text-emerald-50">{{ $activeTermLabel ?? 'No active term selected' }}</p>
        </div>
      </div>

      <nav class="mt-8 flex-1 space-y-6 overflow-y-auto pr-1">
        @foreach ($navGroups as $group)
          <div>
            <p class="px-3 text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">{{ $group['label'] }}</p>
            <div class="mt-3 space-y-1">
              @foreach ($group['items'] as $item)
                <a
                  href="{{ route($item['route']) }}"
                  class="flex items-center justify-between rounded-2xl px-4 py-3 text-sm font-medium transition-all duration-200 {{ request()->routeIs($item['match']) ? 'bg-white/10 text-white shadow-lg shadow-slate-950/20' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}"
                >
                  <span>{{ $item['label'] }}</span>
                  @if (request()->routeIs($item['match']))
                    <span class="h-2.5 w-2.5 rounded-full bg-blue-400"></span>
                  @endif
                </a>

                {{-- Schedule sub-nav: shown when inside schedule pages --}}
                @if ($item['route'] === 'aop.schedule.home' && $inSchedule)
                  <div class="ml-3 mt-1 space-y-0.5 border-l border-white/10 pl-3">
                    @foreach ($scheduleSubNav as $sub)
                      <a
                        href="{{ route($sub['route']) }}"
                        class="flex items-center justify-between rounded-xl px-3 py-2 text-xs font-medium transition-all duration-150 {{ request()->routeIs($sub['match']) ? 'text-blue-200 bg-white/[0.07]' : 'text-slate-400 hover:text-white hover:bg-white/5' }}"
                      >
                        {{ $sub['label'] }}
                        @if (request()->routeIs($sub['match']))
                          <span class="h-1.5 w-1.5 rounded-full bg-blue-400"></span>
                        @endif
                      </a>
                    @endforeach
                  </div>
                @endif
              @endforeach
            </div>
          </div>
        @endforeach
      </nav>

      <div class="mt-6 rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-4">
        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-2xl px-3 py-2 transition-colors hover:bg-white/5 {{ request()->routeIs('profile.*') ? 'bg-white/5' : '' }}">
          <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-500/20 text-sm font-semibold text-blue-100">
            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
          </div>
          <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</div>
            <div class="text-xs text-slate-400">Profile and account</div>
          </div>
        </a>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
          @csrf
          <button type="submit" class="flex w-full items-center justify-between rounded-2xl px-3 py-3 text-sm font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white">
            <span>Log out</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
          </button>
        </form>

        <div class="mt-4 px-3 text-xs font-medium text-slate-500">
          Platform version {{ config('aop.version', '1.0.0') }}
        </div>
      </div>
    </div>
  </aside>

  <div class="min-w-0 flex-1">
    <header class="border-b border-white/50 bg-white/80 backdrop-blur-xl">
      <div class="mx-auto w-full max-w-[1680px] px-6 py-5 xl:px-10">
        <div class="md:hidden">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Academic Ops</p>
              <p class="mt-1 text-sm font-medium text-slate-600">{{ $activeTermLabel ?? 'No active term selected' }}</p>
            </div>
            <a href="{{ route('dashboard') }}" class="btn secondary sm">Dashboard</a>
          </div>

          <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
            @foreach ($navGroups as $group)
              @foreach ($group['items'] as $item)
                <a
                  href="{{ route($item['route']) }}"
                  class="nav-pill whitespace-nowrap {{ request()->routeIs($item['match']) ? 'nav-pill-active bg-slate-900 text-white border-slate-900' : 'nav-pill-idle bg-white text-slate-600 border-slate-200' }}"
                >
                  {{ $item['label'] }}
                </a>
              @endforeach
            @endforeach
          </div>
        </div>

        <div class="hidden md:flex md:items-center md:justify-between md:gap-6">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Overview</p>
            <p class="mt-2 text-sm font-medium text-slate-600">{{ $activeTermLabel ?? 'No active term selected' }}</p>
          </div>
          <div class="flex items-center gap-3">
            <a href="{{ route('aop.schedule.home') }}" class="btn secondary">Open Schedule</a>
            <a href="{{ route('aop.terms.index') }}" class="btn secondary">Manage Terms</a>
          </div>
        </div>
      </div>
    </header>

    <main class="mx-auto w-full max-w-[1680px] px-6 py-8 xl:px-10">
      @if (session('schedule_warnings'))
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50/95 p-4 shadow-sm">
          <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
          <div>
            <div class="text-sm font-bold text-amber-900 mb-1">Scheduling rule advisory</div>
            <ul class="list-disc list-inside text-sm text-amber-800 space-y-0.5 marker:text-amber-500">
              @foreach (session('schedule_warnings') as $warning)
                <li>{{ $warning }}</li>
              @endforeach
            </ul>
            <p class="mt-2 text-xs text-amber-700">The block was saved. These are advisory notices — not errors. Rules will be enforced once the institution finalises its compliance timeline.</p>
          </div>
        </div>
      @endif

      @if (session('status') && !in_array(session('status'), ['profile-updated', 'password-updated'], true))
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/95 p-4 shadow-sm">
          <svg class="mt-0.5 h-5 w-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          <div class="text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        </div>
      @endif

      @if ($errors->any() && !request()->routeIs('profile.*'))
        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50/95 p-4 shadow-sm">
          <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <div>
              <div class="text-sm font-bold text-red-800 mb-1">Please fix the following issues:</div>
              <ul class="list-disc list-inside text-sm text-red-700 marker:text-red-400 space-y-1">
                @foreach ($errors->all() as $e)
                  <li>{{ $e }}</li>
                @endforeach
              </ul>
            </div>
          </div>
        </div>
      @endif

      <div class="pb-12">
        {{ $slot }}
      </div>
    </main>
  </div>
</div>

</body>
</html>
