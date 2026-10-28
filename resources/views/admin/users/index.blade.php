@extends('layouts.app')

@section('content')
    <div class="ui-page">
        <x-ui.page-header title="Users" subtitle="Manage portal accounts, roles and access.">
            <x-slot name="actions">
                <x-ui.button :href="route('admin.users.create')" variant="primary">
                    <x-ui.icon name="plus" :size="16" /> New user
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <div class="ui-toolbar">
            <form method="GET" action="{{ route('admin.users') }}" class="dd-users-filter">
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
        </div>

        <x-ui.card :flush="true">
            <x-ui.table>
                <thead>
                <tr>
                    <th>First</th>
                    <th>Last</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th style="white-space:normal;">Client Organisation</th>
                    <th>Status</th>
                    <th>MFA</th>
                    <th style="text-align:right;">Actions</th>
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
                            <x-ui.badge :variant="$user->is_active ? 'success' : 'neutral'">{{ $user->is_active ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                        </td>
                        <td>
                            <x-ui.badge :variant="$user->two_factor_confirmed_at ? 'success' : 'warning'" :title="'MFA policy: '.ucfirst($user->mfa_preference ?? 'enabled')">{{ $user->two_factor_confirmed_at ? 'Configured' : 'Not configured' }}</x-ui.badge>
                        </td>
                        <td>
                            <div class="flex flex-nowrap items-center justify-end gap-1.5">
                                {{-- Password screen --}}
                                <x-ui.icon-button icon="key" label="Password / reset options" :href="route('admin.users.edit', ['user' => $user, 'password' => 1])" />

                                {{-- Reset MFA --}}
                                <form method="POST"
                                      action="{{ route('admin.users.mfa.reset', $user) }}"
                                      onsubmit="return confirm('Reset MFA for this user? They will need to re-enrol.');">
                                    @csrf
                                    <x-ui.icon-button icon="shield" label="Reset MFA" type="submit" />
                                </form>

                                {{-- Edit user --}}
                                <x-ui.icon-button icon="cog" label="Edit user" :href="route('admin.users.edit', $user)" />

                                {{-- Impersonate --}}
                                <form method="POST"
                                      action="{{ route('admin.users.impersonate', $user) }}">
                                    @csrf
                                    <x-ui.icon-button icon="user" label="Impersonate user" type="submit" />
                                </form>

                                {{-- Delete user --}}
                                <form method="POST"
                                      action="{{ route('admin.users.destroy', $user) }}"
                                      onsubmit="return confirm('Delete this user account permanently?');">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.icon-button icon="trash" label="Delete user" variant="danger" type="submit" />
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.table>

            @if ($users->hasPages())
                <div class="px-4 py-3">{{ $users->links() }}</div>
            @endif
        </x-ui.card>
    </div>
@endsection
