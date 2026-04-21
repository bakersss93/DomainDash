<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\NotificationTrigger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailTemplateMailer
{
    public function sendForEvent(
        string $eventKey,
        array $context,
        ?string $customerRecipient,
        ?int $daysBefore = null
    ): bool {
        $template = $this->resolveTemplate($eventKey, $daysBefore);

        if (! $template) {
            Log::warning('No email template configured for event.', [
                'event' => $eventKey,
                'days_before' => $daysBefore,
            ]);

            return false;
        }

        $recipient = $this->resolveRecipient($template, $customerRecipient);
        if (! $recipient) {
            Log::warning('Unable to send templated email because recipient is missing.', [
                'event' => $eventKey,
                'template_id' => $template->id,
                'template_slug' => $template->slug,
            ]);

            return false;
        }

        [$subject, $body] = $this->renderTemplate($template, $context);

        Mail::raw($body, function ($message) use ($recipient, $subject) {
            $message->to($recipient)->subject($subject);
        });

        Log::info('Templated email dispatched.', [
            'event' => $eventKey,
            'days_before' => $daysBefore,
            'template_id' => $template->id,
            'recipient' => $recipient,
            'subject' => $subject,
        ]);

        return true;
    }

    public function renderTemplate(EmailTemplate $template, array $context): array
    {
        $tokens = [];

        foreach (Arr::dot($context) as $key => $value) {
            $tokens['{{' . $key . '}}'] = is_scalar($value) || $value === null
                ? (string) ($value ?? '')
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return [
            strtr($template->subject, $tokens),
            strtr($template->body, $tokens),
        ];
    }

    private function resolveTemplate(string $eventKey, ?int $daysBefore = null): ?EmailTemplate
    {
        $triggerTemplate = NotificationTrigger::query()
            ->with('emailTemplate')
            ->where('event_key', $eventKey)
            ->when($daysBefore !== null, function ($query) use ($daysBefore) {
                $query->where(function ($inner) use ($daysBefore) {
                    $inner->where('days_before', $daysBefore)
                        ->orWhereNull('days_before');
                });
            })
            ->orderByRaw('CASE WHEN days_before IS NULL THEN 1 ELSE 0 END')
            ->latest('id')
            ->first()?->emailTemplate;

        if ($triggerTemplate) {
            return $triggerTemplate;
        }

        return EmailTemplate::query()
            ->where('trigger_event', $eventKey)
            ->latest('id')
            ->first();
    }

    private function resolveRecipient(EmailTemplate $template, ?string $customerRecipient): ?string
    {
        if ($template->audience === 'admin') {
            return $template->admin_recipient_email;
        }

        return $customerRecipient;
    }
}
