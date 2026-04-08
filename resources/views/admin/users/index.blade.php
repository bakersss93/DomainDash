@extends('layouts.app')

@section('content')
    <div class="dd-page">
        <div class="dd-users-header">
            <h1 class="dd-page-title">Users</h1>
        </div>

        <div class="dd-users-controls" style="display:flex;justify-content:space-between;align-items:center;gap:1.4rem;flex-wrap:nowrap;margin:0 0 1.2rem 0;padding-top:.35rem;">
            <form method="GET" action="{{ route('admin.users') }}" class="dd-users-filter" style="display:flex;align-items:center;gap:.85rem;margin:0;">
                <label for="role">Filter by role</label>
                <div class="role-filter-wrapper">
                    <select name="role"
                            id="role"
                            class="role-filter-select"
                            onchange="this.form.submit()">
                        <option value="">All roles</option>
                        <option value="Administrator" {{ request('role') === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                        <option value="Technician" {{ request('role') === 'Technician' ? 'selected' : '' }}>Technician</option>
                        <option value="Customer" {{ request('role') === 'Customer' ? 'selected' : '' }}>Customer</option>
                    </select>
                </div>
            </form>
            <a href="{{ route('admin.users.create') }}" class="btn-accent dd-users-create-btn" style="margin-left:auto;padding:.66rem 1.2rem;">+ New user</a>
        </div>

        <div class="dd-card">
            <table class="dd-table-clean">
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
                            <div style="display:flex;justify-content:flex-end;gap:12px;align-items:center;">
                                {{-- Password screen (key icon) --}}
                                <a href="{{ route('admin.users.edit', ['user' => $user, 'password' => 1]) }}"
                                   title="Password / reset options"
                                   style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:999px;border:1px solid var(--dd-border);text-decoration:none;background:var(--dd-surface-soft);">
                                    🔑
                                </a>

                                {{-- Reset MFA (shield icon) --}}
                                <form method="POST"
                                      action="{{ route('admin.users.mfa.reset', $user) }}"
                                      onsubmit="return confirm('Reset MFA for this user? They will need to re-enrol.');">
                                    @csrf
                                    <button type="submit"
                                            title="Reset MFA"
                                            style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:999px;border:1px solid var(--dd-border);background:color-mix(in srgb, var(--dd-danger) 15%, transparent);">
                                        🛡️
                                    </button>
                                </form>

                                {{-- Edit user (cog icon) --}}
                                <a href="{{ route('admin.users.edit', $user) }}"
                                   title="Edit user"
                                   style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:999px;border:1px solid var(--dd-border);text-decoration:none;background:var(--dd-surface-soft);">
                                    ⚙️
                                </a>

                                {{-- Impersonate (person icon) --}}
                                <form method="POST"
                                      action="{{ route('admin.users.impersonate', $user) }}">
                                    @csrf
                                    <button type="submit"
                                            title="Impersonate user"
                                            style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:999px;border:1px solid var(--dd-border);background:color-mix(in srgb, var(--dd-accent) 15%, transparent);">
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
        </div>
    </div>
@endsection
