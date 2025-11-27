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
        $data = $request->validate([
            'name'=>'required',
            'allowed_ips'=>'nullable|string',
            'rate_limit_per_hour'=>'required|integer|min:1|max:100000',
            'scopes'=>'nullable|array',
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
        return back()->with('status','API key created: '.$plain);
    }

    public function deactivate(ApiKey $key)
    {
        $key->update(['active'=>false]);
        return back()->with('status','API key deactivated');
    }
}
