<x-aop-layout>
  <x-slot:title>Account Links</x-slot:title>

  <div class="page-shell">
    <section class="page-header">
      <span class="page-eyebrow">Access control</span>
      <h1 class="page-title">Account links</h1>
      <p class="page-subtitle">Link each instructor account explicitly. Email addresses do not grant instructor access.</p>
    </section>

    @if (session('status'))
      <div class="panel-success mb-5" role="status">{{ session('status') }}</div>
    @endif

    <div class="space-y-4">
      @foreach ($users as $user)
        <section class="card flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h2 class="font-semibold text-slate-900">{{ $user->name }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ $user->email }}</p>
            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              {{ $user->is_admin ? 'Administrator' : 'Instructor account' }}
            </p>
          </div>

          <form method="POST" action="{{ route('aop.accounts.instructor.update', $user) }}" class="flex w-full max-w-xl flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            @method('PUT')
            <label class="flex-1 text-sm font-medium text-slate-700">
              Instructor profile
              <select name="instructor_id" class="form-select mt-1 w-full">
                <option value="">No instructor access</option>
                @foreach ($instructors as $instructor)
                  <option value="{{ $instructor->id }}" @selected((int) $user->instructor_id === (int) $instructor->id)>
                    {{ $instructor->name }}{{ $instructor->is_active ? '' : ' (inactive)' }}
                  </option>
                @endforeach
              </select>
            </label>
            <button type="submit" class="btn">Save link</button>
          </form>
        </section>
      @endforeach
    </div>
  </div>
</x-aop-layout>
