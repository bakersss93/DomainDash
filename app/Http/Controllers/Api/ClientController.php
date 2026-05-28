<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Client::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($query) use ($s) {
                $query->where('business_name', 'like', "%{$s}%")
                    ->orWhere('abn', 'like', "%{$s}%")
                    ->orWhere('halopsa_reference', 'like', "%{$s}%")
                    ->orWhere('itglue_org_id', 'like', "%{$s}%");
            });
        }

        if ($request->has('active')) {
            $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(
            $q->orderBy('business_name')->paginate((int) $request->get('per_page', 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name'         => 'required|string|max:255',
            'primary_contact_name'  => 'nullable|string|max:255',
            'email'                 => 'nullable|email|max:255',
            'phone'                 => 'nullable|string|max:50',
            'address'               => 'nullable|string|max:500',
            'city'                  => 'nullable|string|max:100',
            'state'                 => 'nullable|string|max:100',
            'postcode'              => 'nullable|string|max:20',
            'country'               => 'nullable|string|max:2',
            'abn'                   => 'nullable|string|max:64',
            'halopsa_reference'     => 'nullable|string|max:128',
            'itglue_org_id'         => 'nullable|string|max:64',
            'itglue_org_name'       => 'nullable|string|max:255',
            'active'                => 'nullable|boolean',
        ]);

        $client = Client::create($data);

        AuditLogger::logSystem('client.create', "Client created via API: {$client->business_name}.", [
            'service' => 'api',
            'function' => 'client.create',
        ], ['new_values' => ['id' => $client->id, 'business_name' => $client->business_name]]);

        return response()->json($client, 201);
    }

    public function show(Client $client): JsonResponse
    {
        return response()->json($client);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'business_name'         => 'sometimes|required|string|max:255',
            'primary_contact_name'  => 'nullable|string|max:255',
            'email'                 => 'nullable|email|max:255',
            'phone'                 => 'nullable|string|max:50',
            'address'               => 'nullable|string|max:500',
            'city'                  => 'nullable|string|max:100',
            'state'                 => 'nullable|string|max:100',
            'postcode'              => 'nullable|string|max:20',
            'country'               => 'nullable|string|max:2',
            'abn'                   => 'nullable|string|max:64',
            'halopsa_reference'     => 'nullable|string|max:128',
            'itglue_org_id'         => 'nullable|string|max:64',
            'itglue_org_name'       => 'nullable|string|max:255',
            'active'                => 'nullable|boolean',
        ]);

        $client->update($data);

        AuditLogger::logSystem('client.update', "Client updated via API: {$client->business_name}.", [
            'service' => 'api',
            'function' => 'client.update',
            'client_id' => $client->id,
        ], ['new_values' => $data]);

        return response()->json($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        AuditLogger::logSystem('client.delete', "Client deleted via API: {$client->business_name}.", [
            'service' => 'api',
            'function' => 'client.delete',
            'client_id' => $client->id,
        ], ['new_values' => ['id' => $client->id]]);

        $client->delete();

        return response()->json(null, 204);
    }
}
