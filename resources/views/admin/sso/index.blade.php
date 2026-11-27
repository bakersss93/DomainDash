@extends('layouts.app')

@section('content')
    @php $tenantsText = implode("\n", $ms['allowed_tenants']); @endphp
    <div class="ui-page">
        <x-ui.page-header title="Single sign-on" subtitle="Staff sign in with Microsoft Entra ID (SAML). Clients sign in with their own Microsoft work account (Sign in with Microsoft).">
            <x-slot name="actions">
                <x-ui.button :href="route('admin.sso.create')" variant="primary">
                    <x-ui.icon name="plus" :size="16" /> Staff SAML connection
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

        <x-ui.card :flush="true" title="Staff SAML connections" description="One per Entra tenant that signs in your own team. Group to role mapping lives on each connection.">
            @if ($connections->isEmpty())
                <x-ui.empty-state title="No staff connections yet" text="Create a staff connection, paste the values into an Entra enterprise application, then enable it." />
            @else
                <x-ui.table>
                    <thead>
                    <tr><th>Name</th><th>Email domains</th><th>Users</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($connections as $c)
                        <tr>
                            <td><a href="{{ route('admin.sso.edit', $c) }}">{{ $c->name }}</a></td>
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

        <form method="POST" action="{{ route('admin.sso.microsoft') }}">
            @csrf
            <x-ui.card title="Sign in with Microsoft (clients)" description="One multi-tenant Entra app registration in your own tenant. Clients are never created automatically: you add each client user first, then they can link their Microsoft work account.">
                <div class="ui-field">
                    <label class="ui-label">Redirect URI (paste into the Entra app registration, platform "Web")</label>
                    <input class="ui-input" type="text" readonly value="{{ $msRedirectUri }}" onclick="this.select()">
                    <p class="ui-help">Derived from APP_URL. It must match exactly.</p>
                </div>
                <div class="ui-field">
                    <input type="hidden" name="enabled" value="0">
                    <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $ms['enabled']))> Enable Sign in with Microsoft for clients</label>
                </div>
                <x-ui.field label="Application (client) ID" name="client_id" :value="old('client_id', $ms['client_id'])" placeholder="00000000-0000-0000-0000-000000000000" />
                <x-ui.field label="Client secret" name="client_secret" type="password" value="" autocomplete="new-password"
                            :placeholder="$ms['client_secret'] !== '' ? 'Stored. Leave blank to keep it.' : ''"
                            help="Create it under Certificates and secrets in the app registration. Stored encrypted; it is never shown again." />
                <x-ui.field label="Allowed Microsoft tenant IDs (optional)" name="allowed_tenants" as="textarea" rows="3" :value="old('allowed_tenants', $tenantsText)"
                            help="One tenant ID per line. Leave empty to accept any organisation (a user must still already exist in DomainDash). You can also lock a single client to one tenant on the client's edit page." />
                <div class="ui-field">
                    <input type="hidden" name="trust_mfa_clients" value="0">
                    <label class="flex items-start gap-2"><input type="checkbox" name="trust_mfa_clients" value="1" @checked(old('trust_mfa_clients', $ms['trust_mfa_clients']))>
                        <span>Trust Microsoft sign-in for MFA (clients)<small class="ui-help" style="display:block">On: a client who signed in with Microsoft is not asked for DomainDash's own MFA (their own tenant's MFA / Conditional Access applies). Off: DomainDash's MFA is still required.</small></span></label>
                </div>
                <x-slot name="footer">
                    <span class="ui-card__hint">Needs delegated permissions openid, profile, email (User.Read). See docs/microsoft-sign-in.md.</span>
                    <x-ui.button type="submit" variant="primary">Save Microsoft sign-in</x-ui.button>
                </x-slot>
            </x-ui.card>
        </form>

        <form method="POST" action="{{ route('admin.sso.settings') }}">
            @csrf
            <x-ui.card title="Login method policy" description="Choose how each audience may sign in. Microsoft sign-in is never blocked; this controls the password.">
                <x-ui.field label="Staff" name="staff_login" as="select" help="Microsoft only is refused unless an enabled staff SAML connection exists and an active Administrator keeps the password exception.">
                    <option value="both" @selected(old('staff_login', $policy['staff_login']) === 'both')>Password and Microsoft</option>
                    <option value="microsoft_only" @selected(old('staff_login', $policy['staff_login']) === 'microsoft_only')>Microsoft only</option>
                </x-ui.field>
                <x-ui.field label="Clients" name="client_login" as="select" help="Microsoft only is refused unless Sign in with Microsoft is configured above. A client can override this on its own edit page.">
                    <option value="both" @selected(old('client_login', $policy['client_login']) === 'both')>Password and Microsoft</option>
                    <option value="microsoft_only" @selected(old('client_login', $policy['client_login']) === 'microsoft_only')>Microsoft only</option>
                </x-ui.field>
                <fieldset class="ui-field">
                    <legend class="ui-label">Users who may also use a password (exception)</legend>
                    @forelse ($passwordUsers as $u)
                        <p><a href="{{ route('admin.users.edit', $u) }}">{{ $u->name }} &lt;{{ $u->email }}&gt;</a>@unless ($u->is_active) <small>(disabled)</small>@endunless</p>
                    @empty
                        <p class="ui-help">Nobody yet.</p>
                    @endforelse
                    <p class="ui-help">Set the exception on a user's edit page (administrators only). Keep at least one Administrator here as a break-glass login, with its password and authenticator stored safely, and test it before closing password sign-in.</p>
                </fieldset>
                <x-slot name="footer">
                    <span class="ui-card__hint">Refused if it could lock everyone out.</span>
                    <x-ui.button type="submit" variant="primary">Save policy</x-ui.button>
                </x-slot>
            </x-ui.card>
        </form>
    </div>
@endsection
