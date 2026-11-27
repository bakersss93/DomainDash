<?php

namespace App\Services\Notifications;

use App\Models\PlatformCertificateRequest;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Everything the topbar notification centre shows for one user. Admins also get alerts and long-running tasks. */
class NotificationSummary
{
    private const SCOPE_LABELS = [
        'halo/clients' => 'HaloPSA clients refresh',
        'halo/assets' => 'HaloPSA assets refresh',
        'halo/domains' => 'Sync domains to HaloPSA',
        'halo/subscriptions' => 'Sync internet services to HaloPSA',
        'itglue/organizations' => 'IT Glue organisations refresh',
        'itglue/configurations' => 'Sync domains to IT Glue',
        'halo/push-internet' => 'Sync internet service assets to HaloPSA',
        'halo/push-hosting' => 'Sync hosting service assets to HaloPSA',
        'halo/push-ssl' => 'Sync SSL certificate assets to HaloPSA',
        'itglue/push-internet' => 'Sync internet service assets to IT Glue',
        'itglue/push-hosting' => 'Sync hosting service assets to IT Glue',
        'itglue/push-ssl' => 'Sync SSL certificate assets to IT Glue',
        'vocus/services' => 'Vocus internet services refresh',
    ];

    public function __construct(private SystemAlerts $alerts)
    {
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    private static function cacheKey(User $user): string
    {
        return 'notification-summary:' . $user->id;
    }

    public function for(User $user): array
    {
        $ttl = (int) config('domaindash.notifications.cache_seconds', 5);
        if ($ttl <= 0) {
            return $this->build($user);
        }

        return Cache::remember(self::cacheKey($user), $ttl, fn () => $this->build($user));
    }

    private function build(User $user): array
    {
        $isAdmin = $user->hasRole('Administrator');

        $notifications = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'text' => $n->message,
                'url' => $n->link_url,
                'at' => $n->created_at?->toIso8601String(),
                'read_url' => route('notifications.read', $n),
            ])->all();
        $unread = UserNotification::where('user_id', $user->id)->whereNull('read_at')->count();

        $alerts = $isAdmin ? $this->alerts->forUser($user) : [];
        $progress = $isAdmin ? $this->inProgress() : [];

        return [
            'is_admin' => $isAdmin,
            'unread_count' => $unread,
            'alert_count' => count($alerts),
            'count' => $unread + count($alerts),
            'severity' => SystemAlerts::highestSeverity($alerts) ?? ($unread > 0 ? 'info' : null),
            'in_progress_count' => count($progress),
            'in_progress' => $progress,
            'alerts' => $alerts,
            'notifications' => $notifications,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Queued/running sync runs and open certificate requests. */
    private function inProgress(): array
    {
        $items = [];

        try {
            if (Schema::hasTable('sync_runs')) {
                SyncRun::query()
                    ->whereIn('status', [SyncRun::QUEUED, SyncRun::RUNNING])
                    ->where('updated_at', '>=', now()->subHours(6))
                    ->orderBy('id')
                    ->limit(10)
                    ->get()
                    ->each(function (SyncRun $r) use (&$items) {
                        $processed = $r->done + $r->failed + $r->skipped;
                        $key = $r->provider . '/' . $r->scope;
                        $items[] = [
                            'id' => 'sync-' . $r->id,
                            'title' => self::SCOPE_LABELS[$key] ?? ucfirst($r->provider) . ' ' . $r->scope,
                            'status' => $r->status,
                            'detail' => $r->status === SyncRun::QUEUED
                                ? 'Waiting for a queue worker'
                                : ($r->total ? "{$processed} of {$r->total} processed" . ($r->failed ? ", {$r->failed} failed" : '') : 'Working'),
                            'percent' => $r->status === SyncRun::QUEUED ? 0 : ($r->total ? min(100, (int) round($processed / $r->total * 100)) : null),
                            'url' => route('admin.audit.index'),
                        ];
                    });
            }

            if (Schema::hasTable('platform_certificate_requests')) {
                PlatformCertificateRequest::query()
                    ->whereIn('status', [PlatformCertificateRequest::STATUS_PENDING, PlatformCertificateRequest::STATUS_RUNNING])
                    ->latest('id')->limit(3)->get()
                    ->each(function ($r) use (&$items) {
                        $items[] = [
                            'id' => 'cert-' . $r->id,
                            'title' => 'HTTPS certificate request',
                            'status' => $r->status,
                            'detail' => $r->hostname . ($r->status === 'pending' ? ' - waiting for the helper' : ' - in progress'),
                            'percent' => null,
                            'url' => route('admin.https'),
                        ];
                    });
            }
        } catch (\Throwable) {
        }

        return $items;
    }
}
