@extends('layouts.app')

@section('content')
    {{-- Header row: title + New User button --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <h1 style="font-size:18px;font-weight:600;">Users</h1>

        <a href="{{ route('admin.users.create') }}"
           class="btn-accent"
           style="padding:6px 14px;font-size:14px;text-decoration:none;">
            + New user
        </a>
    </div>

    {{-- Role filter --}}
    <form method="GET"
          action="{{ route('admin.users') }}"
          style="margin-bottom:16px;display:flex;align-items:center;gap:12px;">

        <label for="role" style="font-size:14px;">Filter</label>

        <div class="role-filter-wrapper">
            <select name="role"
                    id="role"
                    class="role-filter-select"
                    onchange="this.form.submit()"
                    style="
                        border-radius:9999px;
                        background:#f3f4f6;
                        border:1px solid #e5e7eb;
                        padding:6px 32px 6px 12px;
                        font-size:14px;
                        outline:none;
                        appearance:none;
                        -webkit-appearance:none;
                        -moz-appearance:none;
                        background-clip:padding-box;
                        color:#111827;
                    ">
                <option value="">All roles</option>
                <option value="Administrator" {{ request('role') === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                <option value="Technician"   {{ request('role') === 'Technician'   ? 'selected' : '' }}>Technician</option>
                <option value="Customer"     {{ request('role') === 'Customer'     ? 'selected' : '' }}>Customer</option>
            </select>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>First</th>
            <th>Last</th>
            <th>Email</th>
            <th>Role</th>
            <th>Client Organisation</th>
            <th style="width:260px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($users as $user)
            @php
                $parts = explode(' ', $user->name ?? '', 2);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';
            @endphp
            <tr>
                <td>{{ $first }}</td>
                <td>{{ $last }}</td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->roles->pluck('name')->implode(', ') }}</td>
                <td>
                    {{ $user->clients->pluck('business_name')->implode(', ')
                        ?: $user->clients->pluck('name')->implode(', ')
                        ?: '—' }}
                </td>
                <td>
                    <div style="display:flex;justify-content:flex-end;gap:8px;align-items:center;">
                        {{-- Password screen (key icon) --}}
                        <a href="{{ route('admin.users.password.edit', $user) }}"
                           title="Password / reset options"
                           style="display:inline-flex;align-items:center;justify-content:center;
                                  width:32px;height:32px;border-radius:4px;
                                  border:1px solid #e5e7eb;text-decoration:none;">
                            🔑
                        </a>

                        {{-- Reset MFA (shield icon) --}}
                        <form method="POST"
                              action="{{ route('admin.users.mfa.reset', $user) }}"
                              onsubmit="return confirm('Reset MFA for this user? They will need to re-enrol.');">
                            @csrf
                            <button type="submit"
                                    title="Reset MFA"
                                    style="display:inline-flex;align-items:center;justify-content:center;
                                           width:32px;height:32px;border-radius:4px;
                                           border:1px solid #e5e7eb;background:#fee2e2;">
                                🛡️
                            </button>
                        </form>

                        {{-- Edit user (cog icon) --}}
                        <a href="{{ route('admin.users.edit', $user) }}"
                           title="Edit user"
                           style="display:inline-flex;align-items:center;justify-content:center;
                                  width:32px;height:32px;border-radius:4px;
                                  border:1px solid #e5e7eb;text-decoration:none;">
                            ⚙️
                        </a>

                        {{-- Impersonate (person icon) --}}
                        <form method="POST"
                              action="{{ route('admin.users.impersonate', $user) }}">
                            @csrf
                            <button type="submit"
                                    title="Impersonate user"
                                    style="display:inline-flex;align-items:center;justify-content:center;
                                           width:32px;height:32px;border-radius:4px;
                                           border:1px solid #e5e7eb;background:#e0f2fe;">
                                👤
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{ $users->links() }}
@endsection
