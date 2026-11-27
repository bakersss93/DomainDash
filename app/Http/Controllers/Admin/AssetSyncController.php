<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Assets\AssetEntity;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Http\Request;

/**
 * "Sync to Halo" / "Sync to IT Glue" for ONE internet service, hosting service or SSL certificate, from its own
 * page. Queues the same job as the Sync tabs (so progress shows in the notification centre) and only for an
 * enabled mapping. Route also needs the sync.run permission.
 */
class AssetSyncController extends Controller
{
    public function syncOne(Request $request, SyncDispatcher $dispatcher, string $provider, string $entity, int $id)
    {
        abort_unless(in_array($provider, AssetEntity::PROVIDERS, true) && AssetEntity::valid($entity) && $entity !== AssetEntity::DOMAIN, 404);

        $record = AssetEntity::model($entity)::findOrFail($id);
        $label = $provider === 'halo' ? 'HaloPSA' : 'IT Glue';

        if ($problem = $dispatcher->assetMappingProblem($provider, $entity)) {
            return back()->with('error', $problem);
        }
        if (! $record->client_id) {
            return back()->with('error', 'Assign a client first.');
        }

        $dispatcher->pushAssets($provider, $entity, [$record->getKey()], null, true, $request->user()?->id);

        return back()->with('status', 'Sync of this ' . AssetEntity::singular($entity) . " to {$label} queued.");
    }
}
