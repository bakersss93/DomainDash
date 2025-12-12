<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\DomainController as AdminDomainController;
use App\Http\Controllers\Admin\ApiKeysController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\ClientsController;


Route::get('/', fn() => redirect()->route('dashboard'))->middleware(['auth','verified']);

Route::middleware(['auth','verified'])->group(function () {

        Route::post('/me/toggle-dark', [\App\Http\Controllers\UserSettingsController::class, 'toggleDark'])->middleware(['auth'])->name('me.toggle-dark');

        Route::get('/dashboard', function () {return view('dashboard');})->name('dashboard');

        // DNS (customer-visible for assigned domains)
        Route::get('/domains/{domain}/dns', [DnsController::class, 'index'])->name('dns.index');
        Route::post('/domains/{domain}/dns', [DnsController::class, 'store'])->middleware('permission:dns.manage')->name('dns.store');
        Route::put('/domains/{domain}/dns/{recordId}', [DnsController::class, 'update'])->middleware('permission:dns.manage')->name('dns.update');
        Route::delete('/domains/{domain}/dns/{recordId}', [DnsController::class, 'destroy'])->middleware('permission:dns.manage')->name('dns.destroy');
        Route::post('/domains/{domain}/dns/options', [DnsController::class, 'updateOptions'])->middleware('permission:dns.manage')->name('dns.options');
       
        // Tickets
        Route::get('/tickets/create', [TicketController::class,'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class,'store'])->name('tickets.store');

        // Admin area
        Route::prefix('admin')->middleware(['role:Administrator'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class,'index'])->name('admin.dashboard');
        Route::get('/permissions', [\App\Http\Controllers\Admin\PermissionsController::class,'index'])->name('admin.permissions');
        Route::post('/permissions', [\App\Http\Controllers\Admin\PermissionsController::class,'update'])->name('admin.permissions.update');
        Route::get('/services/ssls', [\App\Http\Controllers\Admin\SslController::class,'index'])->name('admin.services.ssls');
        Route::get('/settings', [SettingsController::class,'index'])->name('admin.settings');
        Route::post('/settings', [SettingsController::class,'update'])->name('admin.settings.update');
        Route::post('/settings/test-smtp', [SettingsController::class,'testSmtp'])->name('admin.settings.smtp-test');

        // ============================================================================
        // SYNC ROUTES
        // ============================================================================
        Route::get('/sync/halo/clients', [\App\Http\Controllers\Admin\SyncController::class, 'getHaloClients']);
        Route::post('/sync/halo/clients/sync', [\App\Http\Controllers\Admin\SyncController::class, 'syncHaloClients']);
        Route::get('/sync/halo/domains', [\App\Http\Controllers\Admin\SyncController::class, 'getHaloDomains']);
        Route::post('/sync/halo/domains/sync', [\App\Http\Controllers\Admin\SyncController::class, 'syncHaloDomains']);

        Route::get('/sync/itglue/clients', [\App\Http\Controllers\Admin\SyncController::class, 'getItGlueClients']);
        Route::post('/sync/itglue/clients/sync', [\App\Http\Controllers\Admin\SyncController::class, 'syncItGlueClients']);
        Route::get('/sync/itglue/suggest/{clientId}', [\App\Http\Controllers\Admin\SyncController::class, 'suggestItGlueOrg']);
        Route::get('/sync/itglue/configurations', [\App\Http\Controllers\Admin\SyncController::class, 'getItGlueConfigurations']);
        Route::post('/sync/itglue/configurations/sync', [\App\Http\Controllers\Admin\SyncController::class, 'syncItGlueConfigurations']);

        // ============================================================================
        // CLIENTS ROUTES
        // ============================================================================
        Route::get('/clients', [ClientsController::class,'index'])->name('admin.clients.index');
        Route::get('/clients/create', [ClientsController::class,'create'])->name('admin.clients.create');
        Route::post('/clients', [ClientsController::class,'store'])->name('admin.clients.store');
        Route::get('/clients/{client}/edit', [ClientsController::class,'edit'])->name('admin.clients.edit');
        Route::put('/clients/{client}', [ClientsController::class,'update'])->name('admin.clients.update');
        Route::delete('/clients/{client}', [ClientsController::class,'destroy'])->name('admin.clients.destroy');

        // HaloPSA Import Routes
        Route::get('/clients/halo-clients', [ClientsController::class, 'haloClients'])->name('admin.clients.haloClients');
        Route::post('/clients/import-halo', [ClientsController::class, 'importHaloClients'])->name('admin.clients.importHalo');
        Route::post('/clients/import-halo/confirm', [ClientsController::class, 'confirmImportHalo'])->name('admin.clients.importHalo.confirm');
        
        // ITGlue Integration Routes
        Route::get('/clients/itglue/search', [ClientsController::class,'itglueSearch'])->name('admin.clients.itglue.search');
        Route::post('/clients/{client}/itglue/link', [ClientsController::class, 'linkItglue'])->name('admin.clients.itglue.link');
        Route::post('/clients/{client}/itglue/sync-domains', [ClientsController::class, 'syncDomainsToItglue'])->name('admin.clients.itglue.syncDomains');
        
        // HaloPSA DNS Sync Routes
        Route::post('/clients/{client}/halo/sync-dns', [ClientsController::class, 'syncDnsToHalo'])->name('admin.clients.halo.syncDns');
        Route::post('/clients/{client}/halo/link-domains', [ClientsController::class, 'linkDomainsFromHalo'])->name('admin.clients.halo.linkDomains');

        // ============================================================================
        // DOMAINS ROUTES
        // ============================================================================
        Route::get('/domains', [AdminDomainController::class,'index'])->name('admin.domains');
        Route::get('/domains/purchase', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'index'])->name('admin.domains.purchase');
        Route::post('/domains/purchase/search', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'search'])->name('admin.domains.purchase.search');
        Route::post('/domains/purchase/validate-au', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'validateAu'])->name('admin.domains.purchase.validateAu');
        Route::post('/domains/purchase/complete', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'complete'])->name('admin.domains.purchase.complete');
        Route::get('/domains/transfer/create', [\App\Http\Controllers\Admin\DomainTransferController::class,'create'])->name('admin.domains.transfer.create');
        Route::post('/domains/transfer/validate', [\App\Http\Controllers\Admin\DomainTransferController::class,'validateTransfer'])->name('admin.domains.transfer.validate');
        Route::post('/domains/transfer/complete', [\App\Http\Controllers\Admin\DomainTransferController::class,'complete'])->name('admin.domains.transfer.complete');
        Route::get('/domains/{domain}', [AdminDomainController::class,'show'])->name('admin.domains.show');
        Route::post('/domains/bulk-sync', [AdminDomainController::class,'bulkSync'])->name('admin.domains.bulkSync');
        Route::post('/domains/availability', [AdminDomainController::class,'searchAvailability'])->name('admin.domains.availability');
        Route::post('/domains/{domain}/renew', [AdminDomainController::class,'renew'])->name('admin.domains.renew');
        Route::post('/domains/transfer', [AdminDomainController::class,'transfer'])->name('admin.domains.transfer');
        Route::post('/domains/{domain}/assign', [AdminDomainController::class,'assignClient'])->name('admin.domains.assignClient');
        Route::get('/domains/{domain}/auth-code', [AdminDomainController::class,'authCode'])->name('admin.domains.auth-code');
            
        // ============================================================================
        // USERS ROUTES
        // ============================================================================
        Route::get('/users', [UsersController::class, 'index'])->name('admin.users');
        Route::get('/users/create', [UsersController::class, 'create'])->name('admin.users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('admin.users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('admin.users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('admin.users.update');
        Route::get('/users/{user}/password', [UsersController::class, 'editPassword'])->name('admin.users.password.edit');
        Route::put('/users/{user}/password', [UsersController::class, 'updatePassword'])->name('admin.users.password.update');
        Route::post('/users/{user}/password-link', [UsersController::class, 'sendPasswordLink'])->name('admin.users.password.link');
        Route::post('/users/{user}/mfa-reset', [UsersController::class, 'resetMfa'])->name('admin.users.mfa.reset');
        Route::post('/users/{user}/impersonate', [UsersController::class, 'impersonate'])->name('admin.users.impersonate');
        Route::post('/users/stop-impersonate', [UsersController::class, 'stopImpersonate'])->name('admin.users.stop-impersonate');

        // ============================================================================
        // API KEYS ROUTES
        // ============================================================================
        Route::get('/api-keys', [ApiKeysController::class,'index'])->name('admin.apikeys');
        Route::post('/api-keys', [ApiKeysController::class,'store'])->name('admin.apikeys.store');
        Route::post('/api-keys/{key}/deactivate', [ApiKeysController::class,'deactivate'])->name('admin.apikeys.deactivate');

        // ============================================================================
        // HOSTING SERVICES ROUTES
        // ============================================================================
        Route::get('/services/hosting', [ServicesController::class, 'index'])->name('admin.services.hosting');
        Route::get('/services/hosting/purchase', [\App\Http\Controllers\Admin\HostingPurchaseController::class,'index'])->name('admin.services.hosting.purchase');
        Route::post('/services/hosting/purchase', [\App\Http\Controllers\Admin\HostingPurchaseController::class,'purchase'])->name('admin.services.hosting.purchase.store');
        Route::post('/services/hosting/sync', [ServicesController::class, 'sync'])->name('admin.services.hosting.sync');
        Route::get('/services/hosting/{service}', [ServicesController::class, 'show'])->name('admin.services.hosting.show');
        Route::get('/services/hosting/{service}/details', [ServicesController::class, 'details'])->name('admin.services.hosting.details');
        Route::post('/services/hosting/{service}/password', [ServicesController::class, 'password'])->name('admin.services.hosting.password');
        Route::post('/services/hosting/{service}/login', [ServicesController::class, 'login'])->name('admin.services.hosting.login');
        Route::post('/services/hosting/{service}/assign-client', [ServicesController::class, 'assignClient'])->name('admin.services.hosting.assignClient');
        Route::post('/services/hosting/{service}/change-domain', [ServicesController::class, 'changePrimaryDomain'])->name('admin.services.hosting.changeDomain');
        Route::post('/services/hosting/{service}/suspend', [ServicesController::class, 'suspend'])->name('admin.services.hosting.suspend');

        // ============================================================================
        // SSL CERTIFICATE ROUTES
        // ============================================================================
        Route::get('/services/ssl/purchase', [\App\Http\Controllers\Admin\SslPurchaseController::class,'index'])->name('admin.services.ssl.purchase');
        Route::post('/services/ssl/purchase', [\App\Http\Controllers\Admin\SslPurchaseController::class,'purchase'])->name('admin.services.ssl.purchase.store');
    });
});