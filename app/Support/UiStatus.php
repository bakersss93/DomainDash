<?php

namespace App\Support;

/**
 * Maps the free-text statuses found across DomainDash (domains, hosting, SSL,
 * internet services, tickets, users, sync runs) onto the semantic variants of
 * the <x-ui.badge> component: success | warning | danger | info | neutral.
 */
class UiStatus
{
    /** @var array<string, list<string>> */
    private const MAP = [
        'success' => [
            'active', 'enabled', 'ok', 'success', 'succeeded', 'completed', 'complete', 'configured',
            'issued', 'valid', 'registered', 'live', 'online', 'connected', 'resolved', 'paid', 'open_ok',
        ],
        'warning' => [
            'pending', 'suspended', 'expiring', 'expiring soon', 'expiring_soon', 'processing', 'in progress',
            'in_progress', 'queued', 'running', 'awaiting', 'on hold', 'on_hold', 'transferring', 'warning',
            'grace', 'partial', 'never synced', 'not configured',
        ],
        'danger' => [
            'expired', 'terminated', 'cancelled', 'canceled', 'failed', 'error', 'rejected', 'revoked',
            'disabled_error', 'invalid', 'overdue', 'lapsed', 'deleted', 'unreachable', 'declined',
        ],
        'info' => [
            'new', 'open', 'info', 'draft', 'scheduled', 'submitted', 'transfer', 'in review', 'reviewing',
        ],
    ];

    /**
     * Semantic badge variant for a status string.
     * Unknown / empty values fall back to "neutral".
     */
    public static function variant(?string $status): string
    {
        $key = self::normalise($status);

        if ($key === '') {
            return 'neutral';
        }

        foreach (self::MAP as $variant => $words) {
            if (in_array($key, $words, true)) {
                return $variant;
            }
        }

        // Partial matches ("Transfer Pending", "SSL expired 3d ago")
        foreach (['danger', 'warning', 'success', 'info'] as $variant) {
            foreach (self::MAP[$variant] as $word) {
                if (str_contains($key, $word)) {
                    return $variant;
                }
            }
        }

        return 'neutral';
    }

    /** Human label (title-cased, underscores to spaces). */
    public static function label(?string $status): string
    {
        $status = trim((string) $status);

        return $status === '' ? '-' : ucfirst(str_replace('_', ' ', strtolower($status)));
    }

    private static function normalise(?string $status): string
    {
        return strtolower(trim(str_replace(['-', '_'], ' ', (string) $status)));
    }
}
