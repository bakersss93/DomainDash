@extends('layouts.app')

@section('content')
    @php
        $days = $cert?->daysRemaining();
        $expiryVariant = $cert === null ? 'neutral' : ($cert->isExpired() || $days <= 7 ? 'danger' : ($days <= 30 ? 'warning' : 'success'));
        $reqVariant = fn ($s) => ['pending' => 'warning', 'running' => 'info', 'succeeded' => 'success', 'failed' => 'danger'][$s] ?? 'neutral';
    @endphp

    <div class="dd-settings-page space-y-4">
        <x-ui.page-header title="Platform HTTPS" subtitle="Serve DomainDash over HTTPS with a Let's Encrypt (DNS-01 via Synergy) or uploaded certificate.">
            <x-slot name="actions">
                <x-ui.button variant="secondary" :href="route('admin.settings')">Back to settings</x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success" dismissible>{{ session('status') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Please fix the following">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        @if ($helperWarning)
            <x-ui.alert variant="warning" title="HTTPS helper not installed or not running">
                {{ $heartbeat['last_seen'] ? 'The helper last checked in '.$heartbeat['last_seen']->diffForHumans().'.' : 'The helper has never checked in.' }}
                Requests stay pending until it runs. On the server, as root:
                <code class="block mt-1">sudo bash deploy/https/install.sh</code>
                then check <code>systemctl status domaindash-certctl.timer</code>. See the README "Platform HTTPS" runbook.
            </x-ui.alert>
        @endif

        <x-ui.card title="Current certificate" description="The certificate nginx is serving for the dashboard.">
            @if ($cert)
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.stat label="Hostname" :value="$cert->hostname" :hint="$cert->staging ? 'Staging (not trusted by browsers)' : null" />
                    <x-ui.stat label="Expires" :value="$cert->not_after?->toDateString()" :variant="$expiryVariant"
                               :hint="$cert->isExpired() ? 'Expired' : $days.' day(s) left'" />
                    <x-ui.stat label="Source" :value="$cert->source === 'letsencrypt' ? 'Let\'s Encrypt' : 'Uploaded'" :hint="$cert->key_type" />
                    <x-ui.stat label="Auto-renew" :value="$cert->auto_renew ? 'On' : 'Off'"
                               :hint="$cert->last_renewed_at ? 'Last renewed '.$cert->last_renewed_at->diffForHumans() : ($cert->auto_renew ? 'Not renewed yet' : 'Upload a new certificate before expiry')" />
                </div>
                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-muted">Issuer</dt><dd>{{ $cert->issuer }}</dd>
                    <dt class="text-muted">Valid from</dt><dd>{{ $cert->not_before?->toDayDateTimeString() }} UTC</dd>
                    <dt class="text-muted">Names (SAN)</dt><dd>{{ implode(', ', $cert->sans ?? []) }}</dd>
                    <dt class="text-muted">SHA-256</dt><dd class="break-all font-mono text-xs">{{ $cert->fingerprint_sha256 }}</dd>
                </dl>
                @if (($renewal['ok'] ?? true) === false)
                    <x-ui.alert variant="danger" class="mt-4" title="Last renewal attempt failed">
                        {{ \Illuminate\Support\Carbon::parse($renewal['at'])->diffForHumans() }}. See the request log below.
                    </x-ui.alert>
                @endif
            @else
                <x-ui.empty-state title="No platform certificate yet" text="Request a Let's Encrypt certificate or upload your own below." icon="shield" />
            @endif
        </x-ui.card>

        <x-ui.card title="Force HTTPS" description="Redirect all http:// requests to https:// and rely on HSTS (sent on every secure response).">
            <form method="POST" action="{{ route('admin.https.force') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="force" value="{{ $force ? 0 : 1 }}">
                <p class="text-sm">
                    Status: <x-ui.badge :variant="$force ? 'success' : 'neutral'">{{ $force ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                    @unless ($canForce)<span class="text-muted"> Requires a valid, unexpired active certificate.</span>@endunless
                </p>
                @if (! $force)
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="confirm" value="1" @disabled(! $canForce)>
                        <span>I have opened the dashboard over https:// and it loads without warnings. {{ $currentlySecure ? '' : '(You are currently on http.)' }}</span>
                    </label>
                @endif
                <x-ui.button type="submit" :variant="$force ? 'secondary' : 'primary'" :disabled="! $force && ! $canForce">{{ $force ? 'Disable Force HTTPS' : 'Enable Force HTTPS' }}</x-ui.button>
                <p class="ui-help">Port 80 closed? The app-level redirect is only reached on port 80 if it is open; otherwise it protects links that arrive via a proxy. Add the optional nginx redirect from deploy/https/nginx-domaindash.conf.example if you open port 80 later.</p>
            </form>
        </x-ui.card>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Let's Encrypt" description="Validated with a DNS TXT record created in Synergy. Port 80 is not needed; wildcards (*.example.com) work.">
                <form method="POST" action="{{ route('admin.https.letsencrypt') }}" class="space-y-3">
                    @csrf
                    <x-ui.field label="Hostname" name="hostname" :value="$defaultHost" required help="Taken from APP_URL. The domain must exist in DomainDash/Synergy." />
                    <x-ui.field label="Contact e-mail" name="email" type="email" :value="auth()->user()->email" required help="Used by Let's Encrypt for expiry notices." />
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="staging" value="1" @checked(old('staging'))> Use the Let's Encrypt staging environment (testing; browsers will not trust it)</label>
                    <x-ui.button type="submit" :disabled="$hasOpen">Request certificate</x-ui.button>
                    @if ($hasOpen)<p class="ui-help">A request is already in progress.</p>@endif
                </form>
            </x-ui.card>

            <x-ui.card title="Upload certificate" description="Use this when DNS is not on Synergy or you buy certificates elsewhere. Validated here and again by the helper.">
                <form method="POST" action="{{ route('admin.https.upload') }}" enctype="multipart/form-data" class="space-y-3" x-data="{ mode: '{{ old('mode', 'pem') }}' }">
                    @csrf
                    <x-ui.field label="Hostname the certificate must cover" name="hostname" :value="$defaultHost" required />
                    <x-ui.field label="Format" name="mode" as="select" x-model="mode">
                        <option value="pem" @selected(old('mode', 'pem') === 'pem')>PEM (certificate + private key + optional chain)</option>
                        <option value="pfx" @selected(old('mode') === 'pfx')>PFX / PKCS#12 (+ password)</option>
                    </x-ui.field>
                    <div x-show="mode === 'pem'" class="space-y-3">
                        <x-ui.field label="Certificate (PEM)" name="certificate" as="textarea" rows="4" help="Or choose a file below." />
                        <input type="file" name="certificate_file" class="ui-input" aria-label="Certificate file">
                        <x-ui.field label="Private key (PEM, no passphrase)" name="private_key" as="textarea" rows="4" autocomplete="off" />
                        <input type="file" name="private_key_file" class="ui-input" aria-label="Private key file">
                        <x-ui.field label="Chain / intermediates (optional, leaf-issuer first)" name="chain" as="textarea" rows="3" />
                        <input type="file" name="chain_file" class="ui-input" aria-label="Chain file">
                    </div>
                    <div x-show="mode === 'pfx'" class="space-y-3">
                        <x-ui.field label="PFX / P12 file" name="pfx_file" type="file" />
                        <x-ui.field label="PFX password" name="pfx_password" type="password" autocomplete="off" />
                    </div>
                    <x-ui.button type="submit" :disabled="$hasOpen">Validate and install</x-ui.button>
                    <p class="ui-help">The private key is stored encrypted and installed root-only (0600) by the helper.</p>
                </form>
            </x-ui.card>
        </div>

        <x-ui.card title="Request history" description="Latest 25 requests. Logs are sanitised (no keys or credentials)." :flush="true">
            @if ($requests->isEmpty())
                <x-ui.empty-state title="No requests yet" icon="inbox" />
            @else
                <x-ui.table>
                    <thead><tr><th>#</th><th>Type</th><th>Hostname</th><th>Status</th><th>Requested</th><th>Result</th></tr></thead>
                    <tbody>
                    @foreach ($requests as $r)
                        <tr>
                            <td>{{ $r->id }}</td>
                            <td>{{ $r->type }}{{ $r->staging ? ' (staging)' : '' }}</td>
                            <td>{{ $r->hostname ?? '-' }}</td>
                            <td><x-ui.badge :variant="$reqVariant($r->status)">{{ ucfirst($r->status) }}</x-ui.badge></td>
                            <td>{{ $r->created_at?->diffForHumans() }}</td>
                            <td>
                                @if ($r->error)<span class="text-sm">{{ $r->error }}</span>@endif
                                @if ($r->log)
                                    <details class="mt-1"><summary class="cursor-pointer text-sm">Log</summary>
                                        <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap text-xs">{{ $r->log }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <p class="ui-help">
            Helper heartbeat: {{ $heartbeat['last_seen'] ? $heartbeat['last_seen']->diffForHumans() : 'never' }}.
            Auto-renewal runs twice daily via <code>domaindash-certbot-renew.timer</code>.
        </p>
    </div>
@endsection
