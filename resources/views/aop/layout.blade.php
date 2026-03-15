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
<body class="font-sans antialiased text-slate-900 bg-slate-50 min-h-screen flex selection:bg-indigo-100 selection:text-indigo-900">

<!-- Sidebar -->
<aside class="w-64 flex-shrink-0 bg-slate-900 text-white flex flex-col justify-between hidden md:flex sticky top-0 h-screen shadow-xl z-20">
  <div>
    <!-- Brand -->
    <div class="p-6 border-b border-white/10">
      <h1 class="text-xl font-extrabold tracking-tight text-white mb-1">Academic Ops Platform</h1>
      <div class="text-xs font-medium text-emerald-400 flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
        {{ $activeTermLabel ?? 'No active term selected' }}
      </div>
    </div>

    <!-- Navigation -->
    <nav class="p-4 space-y-1">
      @php
        $navItems = [
          ['route' => 'dashboard', 'label' => 'Dashboard', 'match' => 'dashboard'],
          ['route' => 'aop.terms.index', 'label' => 'Terms', 'match' => 'aop.terms.*'],
          ['route' => 'aop.instructors.index', 'label' => 'Instructors', 'match' => 'aop.instructors.*'],
          ['route' => 'aop.rooms.index', 'label' => 'Rooms', 'match' => 'aop.rooms.*'],
          ['route' => 'aop.catalog.index', 'label' => 'Catalog', 'match' => 'aop.catalog.*'],
          ['route' => 'aop.schedule.home', 'label' => 'Schedule', 'match' => 'aop.schedule.*'],
        ];
      @endphp

      @foreach($navItems as $item)
        <a href="{{ route($item['route']) }}" 
           class="block px-3 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 {{ request()->routeIs($item['match']) ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
          {{ $item['label'] }}
        </a>
      @endforeach
    </nav>
  </div>

  <!-- Footer Info / Profile -->
  <div class="p-4 border-t border-white/10">
    <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-800 transition-colors duration-200 mb-2 {{ request()->routeIs('profile.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
      <div class="w-8 h-8 rounded-full bg-indigo-500 flex items-center justify-center text-white font-bold text-sm shadow-inner">
        {{ substr(auth()->user()->name, 0, 1) }}
      </div>
      <div>
        <div class="text-sm font-semibold text-white">{{ auth()->user()->name }}</div>
        <div class="text-xs text-slate-400">View Profile</div>
      </div>
    </a>
    
    <form method="POST" action="{{ route('logout') }}" class="mt-2">
      @csrf
      <button type="submit" class="w-full text-left px-3 py-2 text-sm font-medium text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors flex justify-between items-center group">
        Log out
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
      </button>
    </form>
    
    <div class="mt-6 px-2 text-xs text-slate-500 font-mono">
      v{{ config('aop.version', '1.0.0') }}
    </div>
  </div>
</aside>

<!-- Mobile Header (Visible only on small screens) -->
<div class="md:hidden flex flex-col w-full">
  <header class="bg-slate-900 text-white p-4 flex justify-between items-center z-20 shadow-md">
    <div>
      <h1 class="text-lg font-bold">AOP</h1>
      <div class="text-xs text-emerald-400">{{ $activeTermLabel ?? 'No active term selected' }}</div>
    </div>
    
    <!-- Simplified Mobile Nav Dropdown (Optional improvement, keeping standard links for now) -->
    <div class="flex gap-2 text-sm overflow-x-auto pb-1 max-w-[60vw]">
      <a href="{{ route('dashboard') }}" class="text-slate-300">Dashboard</a>
      <a href="{{ route('aop.terms.index') }}" class="text-slate-300">Terms</a>
      <!-- Add others as needed -->
    </div>
  </header>
  
  <main class="flex-1 w-full relative">
    @yield('main-content')
  </main>
</div>


<!-- Main Content Wrapper (Desktop) -->
<div class="flex-1 flex flex-col min-w-0 hidden md:flex relative h-screen overflow-hidden">
  
  <!-- Content Area -->
  <main class="flex-1 overflow-y-auto w-full">
    <div class="max-w-6xl mx-auto p-4 md:p-8">
      
      @if (session('status') && !in_array(session('status'), ['profile-updated', 'password-updated'], true))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 mb-6 shadow-sm flex items-start gap-3">
          <svg class="w-5 h-5 text-emerald-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          <div class="text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        </div>
      @endif

      @if ($errors->any() && !request()->routeIs('profile.*'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 shadow-sm">
          <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
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

      <!-- Page Content Injected Here -->
      <div class="pb-16">
        {{ $slot }}
      </div>
    </div>
  </main>
</div>

</body>
</html>
