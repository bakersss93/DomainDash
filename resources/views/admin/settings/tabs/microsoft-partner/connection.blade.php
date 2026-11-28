@php
    $p = \App\Services\Microsoft\Partner\PartnerSettings::all();
    $configured = \App\Services\Microsoft\Partner\PartnerSettings::configured();
    $lastRun = \App\Models\SyncRun::query()->where('provider', \App\Jobs\SyncPartnerTenantsJob::PROVIDER)->where('scope', \App\Jobs\SyncPartnerTenantsJob::SCOPE)->latest('id')->first();
    $sources = [
        \App\Services\Microsoft\Partner\PartnerSettings::SOURCE_DELEGATED => 'Delegated admin customers (least privilege: DelegatedAdminRelationship.Read.All)',
        \App\Services\Microsoft\Partner\PartnerSettings::SOURCE_CONTRACTS => "Contracts (Directory.Read.All, also returns each customer's default domain)",
        \App\Services\Microsoft\Partner\PartnerSettings::SOURCE_BOTH => 'Both, merged (needs both permissions)',
    ];
@endphp
@if (session('partner_error'))
    <x-ui.alert variant="danger" title="Microsoft Partner">{{ session('partner_error') }}</x-ui.alert>
@endif

<form method="POST" action="{{ route('admin.partner.update') }}">
    @csrf
    <x-ui.card title="Microsoft partner connection" description="An app registration in your own (the partner's) Microsoft Entra tenant, used with a client secret to read your list of customer tenants. Read-only. Setup steps: docs/microsoft-partner-connection.md.">
        <div class="dd-set-form">
            <x-settings.input label="Partner tenant ID" name="partner_tenant_id" :value="$p['partner_tenant_id']" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />
            <x-settings.input label="Application (client) ID" name="client_id" :value="$p['client_id']" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />
            <x-settings.input label="Client secret" name="client_secret" :value="$p['client_secret']" :secret="true" placeholder="Set or update the secret VALUE" help="Stored encrypted and never shown again. Leave as is to keep the stored secret." />
            <x-settings.select label="Customer list source" name="source" :options="$sources" :value="$p['source']" :wide="true" help="Which Microsoft Graph endpoint lists your customers. The permission must be granted to the app with admin consent." />
        </div>
        <x-slot name="footer">
            <div class="dd-set-actions">
                <x-ui.button type="submit">Save connection</x-ui.button>
            </div>
        </x-slot>
    </x-ui.card>
</form>

<x-ui.card title="Test and sync" description="Test checks the secret and permission. Sync now reads your customer tenants into the Customer tenants tab.">
    <div class="dd-set-actions" style="justify-content:flex-start;flex-wrap:wrap;gap:8px;">
        <form method="POST" action="{{ route('admin.partner.test') }}">@csrf
            <x-ui.button type="submit" variant="secondary" :disabled="! $configured">Test connection</x-ui.button>
        </form>
        <form method="POST" action="{{ route('admin.partner.sync') }}">@csrf
            <x-ui.button type="submit" :disabled="! $configured">Sync now</x-ui.button>
        </form>
    </div>
    <p class="dd-set-help" style="margin-top:12px;">
        @if (! $configured)
            Save the connection first.
        @elseif ($lastRun)
            Last sync: <x-ui.badge :status="$lastRun->status" /> {{ ($lastRun->finished_at ?? $lastRun->created_at)?->diffForHumans() }}@if ($lastRun->error) &mdash; {{ $lastRun->error }}@endif
        @else
            No sync has run yet.
        @endif
        A recurring sync can be enabled under Sync scheduler.
    </p>
</x-ui.card>
