@extends('layouts.app')

@section('content')
    <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">Reset Password</h1>

    <p style="margin-bottom:16px;font-size:14px;">
        User: <strong>{{ $user->email }}</strong>
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:24px;max-width:800px;">

        {{-- Left: set password directly --}}
        <form method="POST"
              action="{{ route('admin.users.password.update', $user) }}"
              style="flex:1;min-width:260px;">
            @csrf
            @method('PUT')

            <h2 style="font-size:16px;font-weight:600;margin-bottom:8px;">Set a password</h2>

            <div style="margin-bottom:12px;">
                <label for="password" style="display:block;font-size:14px;margin-bottom:4px;">
                    New password
                </label>
                <input id="password" name="password" type="password" required>
            </div>

            <div style="margin-bottom:12px;">
                <label for="password_confirmation" style="display:block;font-size:14px;margin-bottom:4px;">
                    Confirm password
                </label>
                <input id="password_confirmation" name="password_confirmation" type="password" required>
            </div>

            @error('password')
                <div style="color:#dc2626;font-size:12px;margin-bottom:8px;">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn-accent">
                Save password
            </button>
        </form>

        {{-- Right: send standard Laravel reset link --}}
        <form method="POST"
              action="{{ route('admin.users.password.link', $user) }}"
              style="flex:1;min-width:260px;">
            @csrf

            <h2 style="font-size:16px;font-weight:600;margin-bottom:8px;">Send reset link</h2>

            <p style="font-size:14px;margin-bottom:12px;">
                This will email a standard password reset link to the user so they can choose their
                own password.
            </p>

            <button type="submit" class="btn-accent">
                Send reset email
            </button>
        </form>
    </div>

    <div style="margin-top:24px;">
        <a href="{{ route('admin.users') }}"
           style="font-size:14px;text-decoration:none;border:1px solid #e5e7eb;border-radius:4px;padding:6px 12px;">
            Back to users
        </a>
    </div>
@endsection
