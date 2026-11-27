<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

/**
 * In production the "log" (or "array") mailer writes the whole message to storage/logs or discards it. For password
 * reset, invitation and email-verification mail that means working account-takeover links sitting in a log file
 * (or the user silently never gets the mail). Such messages are refused instead, and an alert is logged.
 * Returning false from a MessageSending listener cancels the send.
 */
class RefuseSensitiveMailViaLogMailer
{
    private const UNSAFE_MAILERS = ['log', 'array'];

    private const SENSITIVE_LINK = '#/(reset-password|team-invitations|email/verify|invitations?|accept-invitation)[/?]#i';

    public function handle(MessageSending $event): ?bool
    {
        if (! app()->environment('production') || ! in_array((string) config('mail.default'), self::UNSAFE_MAILERS, true)) {
            return null;
        }

        if (! $this->isSensitive($event->message)) {
            return null;
        }

        $recipients = $event->message instanceof Email
            ? array_map(fn ($a) => $a->getAddress(), $event->message->getTo())
            : [];

        Log::critical('Refused to send a password-reset / invitation / verification email through the "' . config('mail.default')
            . '" mailer in production: configure SMTP under Settings > Email (or MAIL_MAILER). The message was not written anywhere.', [
            'recipients' => $recipients,
        ]);

        try {
            AuditLogger::logSystem('mail.refused', 'Refused to send a sensitive email: no real mail transport is configured.', [
                'service' => 'mail', 'mailer' => config('mail.default'), 'recipients' => $recipients,
            ]);
        } catch (\Throwable) {
            // alerting must not break the request
        }

        return false;
    }

    private function isSensitive(object $message): bool
    {
        if (! $message instanceof Email) {
            return false;
        }

        $body = (string) $message->getTextBody() . ' ' . (string) $message->getHtmlBody();

        return preg_match(self::SENSITIVE_LINK, $body) === 1;
    }
}
