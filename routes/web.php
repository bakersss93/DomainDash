<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\DomainController as AdminDomainController;
use App\Http\Controllers\Admin\ApiKeysController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\ServicesController;
use App\Http\Controllers\Admin\ClientsController;
use App\Http\Controllers\Admin\DomainPricingController;
use App\Http\Controllers\Admin\EmailNotificationTemplatesController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\UserNotificationController;


Route::get('/', fn() => redirect()->route('dashboard'))->middleware(['auth','verified']);

Route::middleware(['auth','verified','mfa.policy'])->group(function () {

        Route::post('/me/toggle-dark', [\App\Http\Controllers\UserSettingsController::class, 'toggleDark'])->middleware(['auth'])->name('me.toggle-dark');
        Route::get('/me/account', [\App\Http\Controllers\UserSettingsController::class, 'accountDetails'])->name('me.account.details');
        Route::post('/me/account', [\App\Http\Controllers\UserSettingsController::class, 'updateAccount'])->name('me.account.update');
        Route::post('/me/account/password', [\App\Http\Controllers\UserSettingsController::class, 'changePassword'])->name('me.account.password');
        Route::post('/me/account/mfa-reenroll', [\App\Http\Controllers\UserSettingsController::class, 'reEnrollMfa'])->name('me.account.mfa-reenroll');
        Route::get('/me/mfa/setup-status', [\App\Http\Controllers\UserSettingsController::class, 'mfaSetupStatus'])->name('me.mfa.status');
        Route::post('/me/mfa/start', [\App\Http\Controllers\UserSettingsController::class, 'startMfaSetup'])->name('me.mfa.start');
        Route::post('/me/mfa/confirm', [\App\Http\Controllers\UserSettingsController::class, 'confirmMfaSetup'])->name('me.mfa.confirm');
        Route::post('/me/mfa/dismiss', [\App\Http\Controllers\UserSettingsController::class, 'dismissMfaPrompt'])->name('me.mfa.dismiss');

        Route::post('/impersonation/stop', [UsersController::class, 'stopImpersonate'])->name('admin.users.stop-impersonate');

        Route::post('/notifications/{notification}/read', [UserNotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // DNS (customer-visible for assigned domains)
        Route::get('/domains/{domain}/dns', [DnsController::class, 'index'])->name('dns.index');
        Route::post('/domains/{domain}/dns', [DnsController::class, 'store'])->middleware('permission:dns.manage')->name('dns.store');
        Route::put('/domains/{domain}/dns/{recordId}', [DnsController::class, 'update'])->middleware('permission:dns.manage')->name('dns.update');
        Route::delete('/domains/{domain}/dns/{recordId}', [DnsController::class, 'destroy'])->middleware('permission:dns.manage')->name('dns.destroy');
        Route::post('/domains/{domain}/dns/options', [DnsController::class, 'updateOptions'])->middleware('permission:dns.manage')->name('dns.options');
       
        // Tickets
        Route::get('/tickets/create', [TicketController::class,'create'])->name('tickets.create');
        Route::get('/tickets', [TicketController::class,'index'])->name('tickets.index');
        Route::get('/tickets/{ticketId}', [TicketController::class,'show'])->whereNumber('ticketId')->name('tickets.show');
        Route::post('/tickets/{ticketId}/reply', [TicketController::class,'reply'])->whereNumber('ticketId')->name('tickets.reply');
        Route::post('/tickets', [TicketController::class,'store'])->name('tickets.store');

        // Domain admin area (permission-driven for Administrator/Technician roles)
        Route::prefix('admin')->group(function () {
            Route::get('/domains', [AdminDomainController::class,'index'])
                ->middleware('permission:domains.view')
                ->name('admin.domains');
            Route::get('/domains/{domain}', [AdminDomainController::class,'show'])
                ->middleware('permission:domains.view')
                ->whereNumber('domain')
                ->name('admin.domains.show');
            Route::post('/domains/bulk-sync', [AdminDomainController::class,'bulkSync'])
                ->middleware('permission:sync.run')
                ->name('admin.domains.bulkSync');
            Route::post('/domains/availability', [AdminDomainController::class,'searchAvailability'])
                ->middleware('permission:domains.register')
                ->name('admin.domains.availability');
            Route::post('/domains/{domain}/renew', [AdminDomainController::class,'renew'])
                ->middleware('permission:domains.renew')
                ->whereNumber('domain')
                ->name('admin.domains.renew');
            Route::post('/domains/transfer', [AdminDomainController::class,'transfer'])
                ->middleware('permission:domains.transfer')
                ->name('admin.domains.transfer');
            Route::post('/domains/{domain}/assign', [AdminDomainController::class,'assignClient'])
                ->middleware('permission:domains.manage')
                ->whereNumber('domain')
                ->name('admin.domains.assignClient');
            Route::get('/domains/{domain}/auth-code', [AdminDomainController::class,'authCode'])
                ->middleware('permission:domains.transfer')
                ->whereNumber('domain')
                ->name('admin.domains.auth-code');

            Route::get('/domains/pricing', [DomainPricingController::class, 'index'])
                ->middleware('permission:domain-pricing.view')
                ->name('admin.domains.pricing');
            Route::post('/domains/pricing/import', [DomainPricingController::class, 'import'])
                ->middleware('permission:domain-pricing.manage')
                ->name('admin.domains.pricing.import');
            Route::post('/domains/pricing/bulk-markup', [DomainPricingController::class, 'bulkMarkup'])
                ->middleware('permission:domain-pricing.manage')
                ->name('admin.domains.pricing.bulk-markup');
            Route::put('/domains/pricing/{domainPricing}/sell-price', [DomainPricingController::class, 'updateSellPrice'])
                ->middleware('permission:domain-pricing.manage')
                ->name('admin.domains.pricing.sell-price');
            Route::put('/domains/pricing/{domainPricing}/common-domain', [DomainPricingController::class, 'updateCommonDomain'])
                ->middleware('permission:domain-pricing.manage')
                ->name('admin.domains.pricing.common-domain');
        });

        // Admin area
        Route::prefix('admin')->middleware(['role:Administrator'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class,'index'])->name('admin.dashboard');
        Route::get('/permissions', [\App\Http\Controllers\Admin\PermissionsController::class,'index'])->name('admin.permissions');
        Route::post('/permissions', [\App\Http\Controllers\Admin\PermissionsController::class,'update'])->name('admin.permissions.update');
        Route::post('/permissions/roles', [\App\Http\Controllers\Admin\PermissionsController::class,'storeRole'])->name('admin.permissions.roles.store');
        Route::put('/permissions/roles/{role}', [\App\Http\Controllers\Admin\PermissionsController::class,'updateRolePermissions'])->name('admin.permissions.roles.update');
        Route::delete('/permissions/roles/{role}', [\App\Http\Controllers\Admin\PermissionsController::class,'destroyRole'])->name('admin.permissions.roles.destroy');
        Route::get('/services/ssls', [\App\Http\Controllers\Admin\SslController::class,'index'])->name('admin.services.ssls');
        Route::get('/settings', [SettingsController::class,'index'])->name('admin.settings');
        Route::post('/settings', [SettingsController::class,'update'])->name('admin.settings.update');
        Route::get('/settings/halo/ticket-types', [SettingsController::class,'haloTicketTypes'])->name('admin.settings.halo.ticket-types');
        Route::get('/settings/halo/ticket-statuses', [SettingsController::class,'haloTicketStatuses'])->name('admin.settings.halo.ticket-statuses');
        Route::post('/settings/test-smtp', [SettingsController::class,'testSmtp'])->name('admin.settings.smtp-test');
        Route::get('/audit', [AuditLogController::class, 'index'])->name('admin.audit.index');
        Route::post('/audit/retention', [AuditLogController::class, 'updateRetention'])->name('admin.audit.retention');
        Route::get('/notifications/templates', [EmailNotificationTemplatesController::class, 'index'])->name('admin.notifications.templates');
        Route::post('/notifications/templates', [EmailNotificationTemplatesController::class, 'storeTemplate'])->name('admin.notifications.templates.store');
        Route::post('/notifications/templates/{template}', [EmailNotificationTemplatesController::class, 'updateTemplate'])->name('admin.notifications.templates.template.update');
        Route::post('/notifications/triggers', [EmailNotificationTemplatesController::class, 'storeTrigger'])->name('admin.notifications.triggers.store');

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
        Route::get('/sync/ip2whois/domains', [\App\Http\Controllers\Admin\SyncController::class, 'getIp2whoisDomains']);
        Route::post('/sync/ip2whois/domains/sync', [\App\Http\Controllers\Admin\SyncController::class, 'syncIp2whoisDomains']);

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
        // DOMAIN PURCHASE / TRANSFER (admin only)
        // ============================================================================
        Route::get('/domains/purchase', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'index'])->name('admin.domains.purchase');
        Route::post('/domains/purchase/search', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'search'])->name('admin.domains.purchase.search');
        Route::post('/domains/purchase/validate-au', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'validateAu'])->name('admin.domains.purchase.validateAu');
        Route::post('/domains/purchase/complete', [\App\Http\Controllers\Admin\DomainPurchaseController::class,'complete'])->name('admin.domains.purchase.complete');
        Route::get('/domains/transfer/create', [\App\Http\Controllers\Admin\DomainTransferController::class,'create'])->name('admin.domains.transfer.create');
        Route::post('/domains/transfer/validate', [\App\Http\Controllers\Admin\DomainTransferController::class,'validateTransfer'])->name('admin.domains.transfer.validate');
        Route::post('/domains/transfer/complete', [\App\Http\Controllers\Admin\DomainTransferController::class,'complete'])->name('admin.domains.transfer.complete');

        // ============================================================================
        // USERS ROUTES
        // ============================================================================
        Route::get('/users', [UsersController::class, 'index'])->name('admin.users');
        Route::get('/users/create', [UsersController::class, 'create'])->name('admin.users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('admin.users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('admin.users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('admin.users.update');
        Route::delete('/users/{user}', [UsersController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('/users/{user}/password', [UsersController::class, 'editPassword'])->name('admin.users.password.edit');
        Route::put('/users/{user}/password', [UsersController::class, 'updatePassword'])->name('admin.users.password.update');
        Route::post('/users/{user}/password-link', [UsersController::class, 'sendPasswordLink'])->name('admin.users.password.link');
        Route::post('/users/{user}/mfa-reset', [UsersController::class, 'resetMfa'])->name('admin.users.mfa.reset');
        Route::post('/users/{user}/impersonate', [UsersController::class, 'impersonate'])->name('admin.users.impersonate');

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
        Route::post('/services/ssl/sync', [\App\Http\Controllers\Admin\SslController::class,'sync'])->name('admin.services.ssl.sync');
        Route::get('/services/ssl/purchase', [\App\Http\Controllers\Admin\SslPurchaseController::class,'index'])->name('admin.services.ssl.purchase');
        Route::post('/services/ssl/purchase', [\App\Http\Controllers\Admin\SslPurchaseController::class,'purchase'])->name('admin.services.ssl.purchase.store');
        Route::get('/services/ssl/{ssl}', [\App\Http\Controllers\Admin\SslController::class,'show'])->whereNumber('ssl')->name('admin.services.ssl.show');
        Route::post('/services/ssl/{ssl}/certificate', [\App\Http\Controllers\Admin\SslController::class,'getCertificate'])->whereNumber('ssl')->name('admin.services.ssl.certificate');
        Route::get('/services/ssl/{ssl}/bundle.zip', [\App\Http\Controllers\Admin\SslController::class,'downloadBundle'])->whereNumber('ssl')->name('admin.services.ssl.bundleZip');
        Route::post('/services/ssl/{ssl}/resend-completion-email', [\App\Http\Controllers\Admin\SslController::class,'resendCompletionEmail'])->whereNumber('ssl')->name('admin.services.ssl.resendCompletionEmail');
        Route::post('/services/ssl/{ssl}/renew', [\App\Http\Controllers\Admin\SslController::class,'renew'])->whereNumber('ssl')->name('admin.services.ssl.renew');
        Route::post('/services/ssl/{ssl}/rekey', [\App\Http\Controllers\Admin\SslController::class,'rekey'])->whereNumber('ssl')->name('admin.services.ssl.rekey');
        Route::post('/services/ssl/{ssl}/assign-client', [\App\Http\Controllers\Admin\SslController::class,'assignClient'])->whereNumber('ssl')->name('admin.services.ssl.assignClient');
        Route::post('/services/ssl/decode-csr', [\App\Http\Controllers\Admin\SslController::class,'decodeCsr'])->name('admin.services.ssl.decodeCsr');
    });
});
