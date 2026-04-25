<?php

use Illuminate\Support\Facades\Route;
use App\Models\Domain;
use App\Models\HostingService;
use App\Models\SslCertificate;
use App\Http\Middleware\ApiKeyAuth;

Route::middleware([ApiKeyAuth::class.':read'])->group(function(){
    Route::get('/v1/domains', fn() => Domain::select('id','name','expiry_date','client_id')->paginate(100));
    Route::get('/v1/services/hosting', fn() => HostingService::select('id','domain_id','plan','username','ip_address','disk_limit_mb','disk_usage_mb')->paginate(100));
    Route::get('/v1/services/ssl', fn() => SslCertificate::select('id','domain_id','common_name','product_name','expire_date','status')->paginate(100));
    Route::get('/v1/clients/{client}/domains', fn($client) => Domain::where('client_id',$client)->get());
});
