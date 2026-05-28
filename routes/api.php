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
// Domains  (scope: domains.read / domains.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':domains.read'])->group(function () {
    Route::get('/v1/domains', [DomainController::class, 'index']);
    Route::get('/v1/domains/{domain}', [DomainController::class, 'show']);
    Route::get('/v1/domains/{domain}/auth-code', [DomainController::class, 'authCode']);
});

Route::middleware([ApiKeyAuth::class . ':domains.write'])->group(function () {
    Route::patch('/v1/domains/{domain}', [DomainController::class, 'update']);
    Route::post('/v1/domains/sync', [DomainController::class, 'sync']);
    Route::post('/v1/domains/availability', [DomainController::class, 'checkAvailability']);
    Route::post('/v1/domains/transfer', [DomainController::class, 'transfer']);
    Route::post('/v1/domains/{domain}/renew', [DomainController::class, 'renew']);
});

// ---------------------------------------------------------------------------
// DNS Records  (scope: dns.read / dns.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':dns.read'])->group(function () {
    Route::get('/v1/domains/{domain}/dns', [DnsController::class, 'index']);
});

Route::middleware([ApiKeyAuth::class . ':dns.write'])->group(function () {
    Route::post('/v1/domains/{domain}/dns', [DnsController::class, 'store']);
    Route::put('/v1/domains/{domain}/dns/options', [DnsController::class, 'updateOptions']);
    Route::put('/v1/domains/{domain}/dns/{recordId}', [DnsController::class, 'update']);
    Route::delete('/v1/domains/{domain}/dns/{recordId}', [DnsController::class, 'destroy']);
});

// ---------------------------------------------------------------------------
// Clients  (scope: clients.read / clients.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':clients.read'])->group(function () {
    Route::get('/v1/clients', [ClientController::class, 'index']);
    Route::get('/v1/clients/{client}', [ClientController::class, 'show']);
    Route::get('/v1/clients/{client}/domains', [DomainController::class, 'index']);
});

Route::middleware([ApiKeyAuth::class . ':clients.write'])->group(function () {
    Route::post('/v1/clients', [ClientController::class, 'store']);
    Route::put('/v1/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/v1/clients/{client}', [ClientController::class, 'destroy']);
});

// ---------------------------------------------------------------------------
// Hosting Services  (scope: hosting.read / hosting.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':hosting.read'])->group(function () {
    Route::get('/v1/services/hosting', [HostingController::class, 'index']);
    Route::get('/v1/services/hosting/{service}', [HostingController::class, 'show']);
});

Route::middleware([ApiKeyAuth::class . ':hosting.write'])->group(function () {
    Route::post('/v1/services/hosting/sync', [HostingController::class, 'sync']);
});

// ---------------------------------------------------------------------------
// SSL Certificates  (scope: ssl.read / ssl.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':ssl.read'])->group(function () {
    Route::get('/v1/services/ssl', [SslController::class, 'index']);
    Route::get('/v1/services/ssl/{ssl}', [SslController::class, 'show']);
});

Route::middleware([ApiKeyAuth::class . ':ssl.write'])->group(function () {
    Route::post('/v1/services/ssl/sync', [SslController::class, 'sync']);
});

// ---------------------------------------------------------------------------
// Internet Services  (scope: internet.read / internet.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':internet.read'])->group(function () {
    Route::get('/v1/services/internet', [InternetController::class, 'index']);
    Route::get('/v1/services/internet/{service}', [InternetController::class, 'show']);
});

Route::middleware([ApiKeyAuth::class . ':internet.write'])->group(function () {
    Route::post('/v1/services/internet/sync', [InternetController::class, 'sync']);
});

// ---------------------------------------------------------------------------
// Tickets  (scope: tickets.read / tickets.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':tickets.read'])->group(function () {
    Route::get('/v1/tickets', [TicketController::class, 'index']);
    Route::get('/v1/tickets/{ticketId}', [TicketController::class, 'show']);
});

Route::middleware([ApiKeyAuth::class . ':tickets.write'])->group(function () {
    Route::post('/v1/tickets', [TicketController::class, 'store']);
    Route::post('/v1/tickets/{ticketId}/reply', [TicketController::class, 'reply']);
    Route::post('/v1/tickets/{ticketId}/close', [TicketController::class, 'close']);
});

// ---------------------------------------------------------------------------
// Domain Pricing  (scope: pricing.read)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':pricing.read'])->group(function () {
    Route::get('/v1/domain-pricing', [DomainPricingController::class, 'index']);
    Route::get('/v1/domain-pricing/{tld}', [DomainPricingController::class, 'showByTld'])
        ->where('tld', '.+');
});

// ---------------------------------------------------------------------------
// Users  (scope: users.read / users.write)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':users.read'])->group(function () {
    Route::get('/v1/users', [UserController::class, 'index']);
    Route::get('/v1/users/{user}', [UserController::class, 'show']);
});

Route::middleware([ApiKeyAuth::class . ':users.write'])->group(function () {
    Route::post('/v1/users', [UserController::class, 'store']);
    Route::put('/v1/users/{user}', [UserController::class, 'update']);
    Route::delete('/v1/users/{user}', [UserController::class, 'destroy']);
});

// ---------------------------------------------------------------------------
// Audit Logs  (scope: audit.read)
// ---------------------------------------------------------------------------
Route::middleware([ApiKeyAuth::class . ':audit.read'])->group(function () {
    Route::get('/v1/audit-logs', [AuditLogController::class, 'index']);
});
