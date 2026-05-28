<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ApiKey;

class ApiKeysController extends Controller
{
    public function index()
    {
        $keys = ApiKey::orderBy('created_at','desc')->get();
        return view('apikeys.index', compact('keys'));
    }

    public function store(Request $request)
    {
        $validScopes = array_keys(\App\Models\ApiKey::SCOPES);

        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'allowed_ips'         => 'nullable|string',
            'rate_limit_per_hour' => 'required|integer|min:1|max:100000',
            'scopes'              => 'nullable|array',
            'scopes.*'            => 'string|in:' . implode(',', $validScopes),
        ]);
        $plain = bin2hex(random_bytes(24));
        ApiKey::create([
            'name'=>$data['name'],
            'key_hash'=>password_hash($plain, PASSWORD_BCRYPT),
            'allowed_ips'=>$data['allowed_ips'] ?? null,
            'rate_limit_per_hour'=>$data['rate_limit_per_hour'],
            'scopes'=>$data['scopes'] ?? null,
            'active'=>true
        ]);
        return back()->with('new_api_key', $plain);
    }

    public function update(Request $request, ApiKey $key)
    {
        $validScopes = array_keys(\App\Models\ApiKey::SCOPES);

        $data = $request->validate([
            'allowed_ips'         => 'nullable|string',
            'rate_limit_per_hour' => 'required|integer|min:1|max:100000',
            'scopes'              => 'nullable|array',
            'scopes.*'            => 'string|in:' . implode(',', $validScopes),
        ]);

        $key->update([
            'allowed_ips'         => $data['allowed_ips'] ?? null,
            'rate_limit_per_hour' => $data['rate_limit_per_hour'],
            'scopes'              => $data['scopes'] ?? null,
        ]);

        return back()->with('status', 'API key updated.');
    }

    public function destroy(ApiKey $key)
    {
        $key->delete();
        return back()->with('status', 'API key deleted.');
    }

    public function deactivate(ApiKey $key)
    {
        $key->update(['active' => false]);
        return back()->with('status', 'API key deactivated.');
    }

    public function reactivate(ApiKey $key)
    {
        $key->update(['active' => true]);
        return back()->with('status', 'API key reactivated.');
    }

    public function regenerate(ApiKey $key)
    {
        $plain = bin2hex(random_bytes(24));
        $key->update(['key_hash' => password_hash($plain, PASSWORD_BCRYPT)]);
        return back()->with('new_api_key', $plain);
    }
}
