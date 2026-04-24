@extends('layouts.app')

@section('content')
    <div class="dd-page">
        <h1 class="dd-page-title">Roles & Permissions</h1>

        <div class="dd-card" style="margin-bottom:1rem;">
            <form method="POST" action="{{ route('admin.permissions.roles.store') }}" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
                @csrf
                <div style="flex:1;min-width:240px;">
                    <label for="new-role-name" style="display:block;margin-bottom:0.35rem;color:var(--dd-text);font-weight:600;">Create role/group</label>
                    <input id="new-role-name" name="name" type="text" class="dd-input" placeholder="e.g. Billing Manager" required>
                </div>
                <button type="submit" class="btn-accent">Create role</button>
            </form>
        </div>

        <div style="display:grid;gap:1rem;">
            @foreach($roles as $role)
                <section class="dd-card">
                    <div style="display:flex;justify-content:space-between;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:0.85rem;">
                        <div>
                            <h2 style="margin:0;color:var(--dd-text);font-size:1.1rem;">{{ $role->name }}</h2>
                            <p style="margin:0.2rem 0 0;color:var(--dd-text-soft);font-size:0.9rem;">Assign permissions for this group.</p>
                        </div>
                        @if($role->name !== 'Administrator')
                            <form method="POST" action="{{ route('admin.permissions.roles.destroy', $role) }}" onsubmit="return confirm('Delete this role? Users assigned to it will lose role access.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="dd-account-secondary">Delete role</button>
                            </form>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('admin.permissions.roles.update', $role) }}">
                        @csrf
                        @method('PUT')

                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0.55rem 1rem;">
                            @foreach($permissions as $permission)
                                <label style="display:flex;align-items:center;gap:0.5rem;color:var(--dd-text);font-size:0.92rem;">
                                    <input type="checkbox"
                                           name="permissions[]"
                                           value="{{ $permission->name }}"
                                           @checked($role->hasPermissionTo($permission->name))>
                                    <span>{{ $permission->name }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div style="margin-top:1rem;display:flex;justify-content:flex-end;">
                            <button type="submit" class="btn-accent">Save permissions</button>
                        </div>
                    </form>
                </section>
            @endforeach
        </div>
    </div>
@endsection
