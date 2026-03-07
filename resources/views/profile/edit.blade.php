<x-aop-layout :activeTermLabel="$activeTermLabel">
    <x-slot:title>Profile</x-slot:title>

    <div class="row" style="margin-bottom:14px;">
        <h1>Profile</h1>
    </div>

    <div class="grid">
        <div class="card col-6">
            <h2>Profile Information</h2>
            <p>Update your account name and email address.</p>

            @if (session('status') === 'profile-updated')
                <div class="status" style="margin-top:12px;">Profile updated.</div>
            @endif

            <form method="POST" action="{{ route('profile.update') }}" style="margin-top:12px;">
                @csrf
                @method('PATCH')

                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autocomplete="name">
                @if ($errors->has('name'))
                    <div class="field-error">{{ $errors->first('name') }}</div>
                @endif

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                @if ($errors->has('email'))
                    <div class="field-error">{{ $errors->first('email') }}</div>
                @endif

                <div class="actions" style="margin-top:14px;">
                    <button class="btn" type="submit">Save Profile</button>
                </div>
            </form>
        </div>

        <div class="card col-6">
            <h2>Change Password</h2>
            <p>Use a long, unique password for this admin account.</p>

            @if (session('status') === 'password-updated')
                <div class="status" style="margin-top:12px;">Password updated.</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" style="margin-top:12px;">
                @csrf
                @method('PUT')

                <label for="current_password">Current Password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                @if ($errors->updatePassword->has('current_password'))
                    <div class="field-error">{{ $errors->updatePassword->first('current_password') }}</div>
                @endif

                <label for="password">New Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password">
                @if ($errors->updatePassword->has('password'))
                    <div class="field-error">{{ $errors->updatePassword->first('password') }}</div>
                @endif

                <label for="password_confirmation">Confirm New Password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                @if ($errors->updatePassword->has('password_confirmation'))
                    <div class="field-error">{{ $errors->updatePassword->first('password_confirmation') }}</div>
                @endif

                <div class="actions" style="margin-top:14px;">
                    <button class="btn" type="submit">Update Password</button>
                </div>
            </form>
        </div>

        <div class="card col-12" style="border-color:#fecaca;">
            <h2>Delete Account</h2>
            <p>This permanently deletes the current account. This action cannot be undone.</p>

            <form method="POST" action="{{ route('profile.destroy') }}" style="margin-top:12px; max-width:420px;">
                @csrf
                @method('DELETE')

                <label for="delete_password">Confirm with Password</label>
                <input id="delete_password" name="password" type="password" autocomplete="current-password">
                @if ($errors->userDeletion->has('password'))
                    <div class="field-error">{{ $errors->userDeletion->first('password') }}</div>
                @endif

                <div class="actions" style="margin-top:14px;">
                    <button class="btn danger" type="submit" onclick="return confirm('Delete this account permanently?');">Delete Account</button>
                </div>
            </form>
        </div>
    </div>
</x-aop-layout>
