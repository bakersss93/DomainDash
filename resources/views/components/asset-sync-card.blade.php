@props(['entity', 'record'])

{{--
  Asset sync status of one internet service / hosting service / SSL certificate in HaloPSA and IT Glue
  (documentation asset records; internet service billing is the separate Halo billing card).
--}}
@php
    use App\Services\Assets\AssetEntity;
    use App\Services\Halo\HaloAssetMapping;
    use App\Services\ItGlue\ItGlueAssetMapping;

    $rows = [];
    foreach (['halo' => 'HaloPSA', 'itglue' => 'IT Glue'] as $provider => $providerLabel) {
        $mapping = $provider === 'halo' ? HaloAssetMapping::forEntity($entity) : ItGlueAssetMapping::forEntity($entity);
        $cols = AssetEntity::columns($entity, $provider);
        $on = $mapping->enabled && $mapping->typeId;
        $error = $record->{$cols['error']};
        $at = $record->{$cols['synced']};
        $chip = match (true) {
            ! $on => ['Off', 'var(--dd-status-muted-bg)', 'var(--dd-status-muted-fg)'],
            (bool) $error => ['Sync failed', 'var(--dd-status-danger-bg)', 'var(--dd-status-danger-fg)'],
            (bool) $at => ['Synced', 'var(--dd-status-success-bg)', 'var(--dd-status-success-fg)'],
            default => ['Not synced yet', 'var(--dd-status-warning-bg)', 'var(--dd-status-warning-fg)'],
        };
        $rows[] = compact('provider', 'providerLabel', 'on', 'error', 'at', 'chip', 'mapping') + ['assetId' => $record->{$cols['id']}];
    }
    $canSync = auth()->user()?->can('sync.run');
@endphp
<div id="asset-sync-card" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 4px;color:var(--text);">Asset records</h3>
    <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 12px;">Documents this {{ AssetEntity::singular($entity) }} as an asset in the mapped asset type.</p>
    @foreach ($rows as $r)
        <div style="padding:10px 0;{{ $loop->first ? '' : 'border-top:1px solid var(--border-subtle);' }}">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <strong style="font-size:13px;color:var(--text);">{{ $r['providerLabel'] }}</strong>
                <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:{{ $r['chip'][1] }};color:{{ $r['chip'][2] }};">{{ $r['chip'][0] }}</span>
            </div>
            @if ($r['on'])
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:8px 0 0;">
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Asset type</dt>
                    <dd style="font-size:13px;margin:0;">{{ $r['mapping']->typeLabel() }}</dd>
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Asset</dt>
                    <dd style="font-size:13px;margin:0;">{{ $r['assetId'] ? '#' . $r['assetId'] : '-' }}</dd>
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Last synced</dt>
                    <dd style="font-size:13px;margin:0;">{{ $r['at'] ? $r['at']->diffForHumans() : 'never' }}</dd>
                </dl>
                @if ($r['error'])
                    <p role="alert" style="font-size:13px;margin:8px 0 0;color:var(--dd-danger-text);word-break:break-word;">{{ $r['error'] }}</p>
                @endif
                @if ($canSync)
                    <form method="POST" action="{{ route('admin.assets.sync', [$r['provider'], $entity, $record->getKey()]) }}" style="margin-top:10px;">
                        @csrf
                        <button type="submit" class="btn-accent" style="padding:6px 14px;font-size:13px;" @disabled(! $record->client_id)>Sync to {{ $r['provider'] === 'halo' ? 'Halo' : 'IT Glue' }}</button>
                        @unless ($record->client_id)
                            <span style="font-size:12px;color:var(--dd-text-soft);margin-left:8px;">Assign a client first.</span>
                        @endunless
                    </form>
                @endif
            @else
                <p style="font-size:12px;color:var(--dd-text-soft);margin:6px 0 0;">Turn this on in Settings → {{ $r['providerLabel'] }} → Assets.</p>
            @endif
        </div>
    @endforeach
</div>
