@extends('layouts.app')

@section('content')
    @php
        $c = $connection;
        $v = fn ($f, $d = null) => old($f, $c->$f ?? $d);
    @endphp
    <div class="ui-page" style="max-width: 980px;">
        <x-ui.page-header :title="$c->exists ? 'Edit staff SSO connection' : 'New staff SSO connection'"
                          subtitle="Microsoft Entra ID (SAML 2.0) for your own staff. Fill in the Entra side, then paste the values from the first card into the Entra enterprise application.">
            <x-slot name="actions">
                <x-ui.button :href="route('admin.sso.index')" variant="secondary">Back</x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success" :dismissible="true">{{ session('status') }}</x-ui.alert>
        @endif
        @if (session('sso_check_problems'))
            <x-ui.alert variant="warning" title="Configuration check found problems">
                <ul class="list-disc pl-5">@foreach ((array) session('sso_check_problems') as $p)<li>{{ $p }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Please fix the following">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        @if ($c->exists)
            <x-ui.card title="Values to paste into Microsoft Entra" description="Enterprise application > Single sign-on > Basic SAML Configuration.">
                @foreach ([
                    'Identifier (Entity ID)' => $c->spEntityId(),
                    'Reply URL (Assertion Consumer Service URL)' => $c->acsUrl(),
                    'Sign-on URL' => $c->loginUrl(),
                    'SP metadata (optional, for upload)' => $c->spEntityId(),
                ] as $label => $value)
                    <div class="ui-field">
                        <label class="ui-label">{{ $label }}</label>
                        <input class="ui-input" type="text" readonly value="{{ $value }}" onclick="this.select()">
                    </div>
                @endforeach
                <p class="ui-help">Leave Relay State and Logout URL empty. Single logout is not used: signing out of DomainDash does not sign you out of Microsoft.</p>
                <x-slot name="footer">
                    <form method="POST" action="{{ route('admin.sso.check', $c) }}">@csrf
                        <x-ui.button type="submit" variant="secondary">Check configuration</x-ui.button>
                    </form>
                    @if ($c->enabled)
                        <x-ui.button :href="$c->loginUrl()" variant="primary" target="_blank" rel="noopener">Test sign-in</x-ui.button>
                    @endif
                </x-slot>
            </x-ui.card>
        @endif

        <form method="POST" action="{{ $c->exists ? route('admin.sso.update', $c) : route('admin.sso.store') }}">
            @csrf
            @if ($c->exists) @method('PUT') @endif
            @unless ($c->exists)
                <input type="hidden" name="type" value="{{ $c->type }}">
            @endunless

            <x-ui.card title="General">
                <x-ui.field label="Name" name="name" :value="$v('name')" required help="Shown to admins only, e.g. &quot;Staff sign-in&quot;." />

                <p class="ui-help">Staff only: this connection signs in your own team. Client users sign in with Microsoft sign-in (see Single sign-on).</p>

                <x-ui.field label="Allowed email domains" name="domains" as="textarea" rows="3" :value="old('domains', $domainsText)"
                            help="One per line, for example contoso.com. Used to route people to this connection from the login page, and every sign-in must come from one of these domains. A domain can belong to one connection only." />

                <div class="ui-field">
                    <input type="hidden" name="enabled" value="0">
                    <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $c->enabled))> Enabled</label>
                </div>
            </x-ui.card>

            <x-ui.card title="Microsoft Entra (identity provider)" description="Easiest: import the Federation Metadata XML. Or type the values by hand.">
                <x-ui.field label="Entra tenant ID (Directory ID)" name="entra_tenant_id" :value="$v('entra_tenant_id')" required
                            help="Every assertion must carry this tenant ID, so another tenant can never sign in here." />
                <x-ui.field label="App Federation Metadata URL" name="metadata_url" :value="$v('metadata_url')" help="From the Entra enterprise app (SAML Certificates). Must be https." />
                <div class="ui-field">
                    <label class="flex items-center gap-2"><input type="checkbox" name="fetch_metadata" value="1"> Fetch the metadata URL now and fill the fields below</label>
                </div>
                <x-ui.field label="...or paste Federation Metadata XML" name="metadata_xml" as="textarea" rows="4" value="" />
                <x-ui.field label="Microsoft Entra Identifier (IdP entity ID)" name="idp_entity_id" :value="$v('idp_entity_id')" help="Looks like https://sts.windows.net/{tenant-id}/" />
                <x-ui.field label="Login URL (IdP sign-on URL)" name="idp_sso_url" :value="$v('idp_sso_url')" />
                <x-ui.field label="Logout URL (not used yet)" name="idp_slo_url" :value="$v('idp_slo_url')" />
                <x-ui.field label="Signing certificate (Base64)" name="idp_x509_cert" as="textarea" rows="4" :value="$v('idp_x509_cert')" />
                <x-ui.field label="Second signing certificate (for rollover)" name="idp_x509_cert_2" as="textarea" rows="3" :value="$v('idp_x509_cert_2')"
                            help="Add the new certificate here before Entra starts using it; remove the old one afterwards." />
                @if ($c->exists && $c->metadata_url)
                    <x-slot name="footer">
                        <button type="submit" class="ui-btn ui-btn--secondary" form="sso-refresh">Refresh certificates from metadata URL</button>
                    </x-slot>
                @endif
            </x-ui.card>

            <x-ui.card title="Who gets in, and with which role">
                <fieldset class="ui-field">
                    <legend class="ui-label">Entra group to role mapping</legend>
                    <p class="ui-help">For each role, paste the Object ID of the Entra security group whose members get it (one per line, several groups allowed). Only staff roles are listed; the list follows the roles defined in DomainDash. Roles are re-read from the groups on every sign-in.</p>
                    @forelse ($roles as $r)
                        <div class="ui-field">
                            <label class="ui-label" for="group_map_{{ \Illuminate\Support\Str::slug($r) }}">{{ $r }}</label>
                            <textarea class="ui-input" id="group_map_{{ \Illuminate\Support\Str::slug($r) }}" name="group_map[{{ $r }}]" rows="2" placeholder="11111111-2222-3333-4444-555555555555">{{ old('group_map.'.$r, $groupsByRole[$r] ?? '') }}</textarea>
                        </div>
                    @empty
                        <p class="ui-help">No staff roles exist yet.</p>
                    @endforelse
                    @error('group_role_map')<div class="ui-error" role="alert">{{ $message }}</div>@enderror
                </fieldset>
                <x-ui.field label="Default role (when no group matches)" name="default_role" as="select" help="Leave blank to deny sign-in when no mapped group matches.">
                    <option value="">None (deny)</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r }}" @selected($v('default_role') === $r)>{{ $r }}</option>
                    @endforeach
                </x-ui.field>
                <div class="ui-field">
                    <input type="hidden" name="jit_provisioning" value="0">
                    <label class="flex items-start gap-2"><input type="checkbox" name="jit_provisioning" value="1" @checked(old('jit_provisioning', $c->jit_provisioning))>
                        <span>Create accounts automatically on first sign-in<small class="ui-help" style="display:block">Off: only people who already have a DomainDash account can sign in.</small></span></label>
                </div>
                <div class="ui-field">
                    <input type="hidden" name="skip_local_mfa" value="0">
                    <label class="flex items-start gap-2"><input type="checkbox" name="skip_local_mfa" value="1" @checked(old('skip_local_mfa', $c->skip_local_mfa))>
                        <span>Trust Microsoft for MFA (skip DomainDash's own MFA for SSO sign-ins)<small class="ui-help" style="display:block"><strong>Warning:</strong> enabling this relies entirely on Entra Conditional Access enforcing MFA for this application. If no such policy exists, SSO users will sign in with a password only. Leave unticked for staff unless you have verified the policy.</small></span></label>
                </div>
                <x-slot name="footer">
                    <span class="ui-card__hint">Accounts are matched by Entra object ID after the first sign-in.</span>
                    <x-ui.button type="submit" variant="primary">{{ $c->exists ? 'Save connection' : 'Create connection' }}</x-ui.button>
                </x-slot>
            </x-ui.card>
        </form>

        @if ($c->exists)
            <form id="sso-refresh" method="POST" action="{{ route('admin.sso.refresh', $c) }}">@csrf</form>
            <form method="POST" action="{{ route('admin.sso.destroy', $c) }}" onsubmit="return confirm('Delete this connection? Linked users keep their account but can no longer sign in with SSO.');">
                @csrf @method('DELETE')
                <x-ui.button type="submit" variant="danger">Delete connection</x-ui.button>
            </form>
        @endif
    </div>
@endsection
