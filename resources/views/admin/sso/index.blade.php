@extends('layouts.app')

@section('content')
    <div class="ui-page">
        <x-ui.page-header title="Single sign-on" subtitle="Microsoft Entra ID (SAML 2.0) for staff and for each client's own Entra tenant.">
            <x-slot name="actions">
                <x-ui.button :href="route('admin.sso.create', ['type' => 'staff'])" variant="primary">
                    <x-ui.icon name="plus" :size="16" /> Staff connection
                </x-ui.button>
                <x-ui.button :href="route('admin.sso.create', ['type' => 'client'])" variant="secondary">
                    <x-ui.icon name="plus" :size="16" /> Client connection
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success" :dismissible="true">{{ session('status') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Please fix the following">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        <x-ui.card :flush="true" title="Connections">
            @if ($connections->isEmpty())
                <x-ui.empty-state title="No SSO connections yet" text="Create a staff connection first, then one per client that wants to sign in with its own Microsoft tenant." />
            @else
                <x-ui.table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Client</th>
                        <th>Email domains</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($connections as $c)
                        <tr>
                            <td><a href="{{ route('admin.sso.edit', $c) }}">{{ $c->name }}</a></td>
                            <td><x-ui.badge :variant="$c->isStaff() ? 'primary' : 'info'" :dot="false">{{ ucfirst($c->type) }}</x-ui.badge></td>
                            <td>{{ $c->client?->business_name ?? '-' }}</td>
                            <td style="white-space:normal;">{{ $c->domains->pluck('domain')->implode(', ') ?: '-' }}</td>
                            <td>{{ $c->users_count }}</td>
                            <td><x-ui.badge :variant="$c->enabled ? 'success' : 'neutral'">{{ $c->enabled ? 'Enabled' : 'Disabled' }}</x-ui.badge></td>
                            <td style="text-align:right;">
                                <x-ui.button :href="route('admin.sso.edit', $c)" variant="secondary" size="sm">Edit</x-ui.button>
                                @if ($c->enabled)
                                    <x-ui.button :href="$c->loginUrl()" variant="ghost" size="sm" target="_blank" rel="noopener">Test sign-in</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <form method="POST" action="{{ route('admin.sso.settings') }}">
            @csrf
            <x-ui.card title="Staff sign-in policy" description="Close password sign-in to staff once SSO works. Customers are not affected.">
                <div class="ui-field">
                    <input type="hidden" name="enforce_staff" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="enforce_staff" value="1" @checked(old('enforce_staff', $sso['enforce_staff']))>
                        <span><strong>Enforce SSO for staff.</strong> Staff users (anyone who is not a customer) must sign in with Microsoft. Only the break-glass accounts below keep their password.</span>
                    </label>
                </div>
                <fieldset class="ui-field">
                    <legend class="ui-label">Break-glass administrators (local password, never linked to SSO)</legend>
                    @forelse ($admins as $a)
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="break_glass[]" value="{{ $a->id }}" @checked($a->is_break_glass) @disabled($a->saml_connection_id)>
                            <span>{{ $a->name }} &lt;{{ $a->email }}&gt;@if ($a->saml_connection_id) <small>(linked to SSO)</small>@endif</span>
                        </label>
                    @empty
                        <p class="ui-help">No active administrators.</p>
                    @endforelse
                    <p class="ui-help">Pick at least one, keep its password and authenticator in a safe place, and test it before enforcing.</p>
                </fieldset>
                <x-slot name="footer">
                    <span class="ui-card__hint">Saving enforcement is refused if there is no break-glass admin or no enabled staff connection.</span>
                    <x-ui.button type="submit" variant="primary">Save policy</x-ui.button>
                </x-slot>
            </x-ui.card>
        </form>
    </div>
@endsection
