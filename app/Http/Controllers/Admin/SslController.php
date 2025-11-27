<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SslCertificate;
use App\Models\Client;

class SslController extends Controller
{
    public function index(Request $request)
    {
        $q = SslCertificate::query()->with('domain','client');
        if ($client = $request->get('client_id')) $q->where('client_id',$client);
        $ssls = $q->paginate(25);
        $clients = Client::orderBy('business_name')->get();
        return view('admin.ssls.index', compact('ssls','clients'));
    }
}
