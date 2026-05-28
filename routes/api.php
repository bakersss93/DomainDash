<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ApiKeyAuth;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DnsController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\DomainPricingController;
use App\Http\Controllers\Api\HostingController;
use App\Http\Controllers\Api\InternetController;
use App\Http\Controllers\Api\SslController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;

// ---------------------------------------------------------------------------
// Read-only endpoints  (scope: read)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':read'])->group(function () {

    // Domains
    Route::get('/v1/domains', [DomainController::class, 'index']);
    Route::get('/v1/domains/{domain}', [DomainController::class, 'show']);
    Route::get('/v1/domains/{domain}/auth-code', [DomainController::class, 'authCode']);
    Route::get('/v1/domains/{domain}/dns', [DnsController::class, 'index']);

    // Clients
    Route::get('/v1/clients', [ClientController::class, 'index']);
    Route::get('/v1/clients/{client}', [ClientController::class, 'show']);
    Route::get('/v1/clients/{client}/domains', [DomainController::class, 'index']);

    // Services
    Route::get('/v1/services/hosting', [HostingController::class, 'index']);
    Route::get('/v1/services/hosting/{service}', [HostingController::class, 'show']);
    Route::get('/v1/services/ssl', [SslController::class, 'index']);
    Route::get('/v1/services/ssl/{ssl}', [SslController::class, 'show']);
    Route::get('/v1/services/internet', [InternetController::class, 'index']);
    Route::get('/v1/services/internet/{service}', [InternetController::class, 'show']);

    // Tickets
    Route::get('/v1/tickets', [TicketController::class, 'index']);
    Route::get('/v1/tickets/{ticketId}', [TicketController::class, 'show']);

    // Domain pricing
    Route::get('/v1/domain-pricing', [DomainPricingController::class, 'index']);
    Route::get('/v1/domain-pricing/{tld}', [DomainPricingController::class, 'showByTld'])
        ->where('tld', '.+');

    // Users
    Route::get('/v1/users', [UserController::class, 'index']);
    Route::get('/v1/users/{user}', [UserController::class, 'show']);

    // Audit logs
    Route::get('/v1/audit-logs', [AuditLogController::class, 'index']);
});

// ---------------------------------------------------------------------------
// Write endpoints  (scope: write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':write'])->group(function () {

    // Domains
    Route::patch('/v1/domains/{domain}', [DomainController::class, 'update']);
    Route::post('/v1/domains/sync', [DomainController::class, 'sync']);
    Route::post('/v1/domains/availability', [DomainController::class, 'checkAvailability']);
    Route::post('/v1/domains/transfer', [DomainController::class, 'transfer']);
    Route::post('/v1/domains/{domain}/renew', [DomainController::class, 'renew']);

    // DNS
    Route::post('/v1/domains/{domain}/dns', [DnsController::class, 'store']);
    Route::put('/v1/domains/{domain}/dns/{recordId}', [DnsController::class, 'update']);
    Route::delete('/v1/domains/{domain}/dns/{recordId}', [DnsController::class, 'destroy']);
    Route::put('/v1/domains/{domain}/dns/options', [DnsController::class, 'updateOptions']);

    // Clients
    Route::post('/v1/clients', [ClientController::class, 'store']);
    Route::put('/v1/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/v1/clients/{client}', [ClientController::class, 'destroy']);

    // Services – sync
    Route::post('/v1/services/hosting/sync', [HostingController::class, 'sync']);
    Route::post('/v1/services/ssl/sync', [SslController::class, 'sync']);
    Route::post('/v1/services/internet/sync', [InternetController::class, 'sync']);

    // Tickets
    Route::post('/v1/tickets', [TicketController::class, 'store']);
    Route::post('/v1/tickets/{ticketId}/reply', [TicketController::class, 'reply']);
    Route::post('/v1/tickets/{ticketId}/close', [TicketController::class, 'close']);

    // Users
    Route::post('/v1/users', [UserController::class, 'store']);
    Route::put('/v1/users/{user}', [UserController::class, 'update']);
    Route::delete('/v1/users/{user}', [UserController::class, 'destroy']);
});
