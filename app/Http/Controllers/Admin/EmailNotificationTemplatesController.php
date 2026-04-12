<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\NotificationTrigger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailNotificationTemplatesController extends Controller
{
    private const DEFAULT_TEMPLATES = [
        [
            'name' => 'Domain Expiry Reminder',
            'slug' => 'domain-expiry-reminder',
            'trigger_event' => 'domain_expiring',
            'audience' => 'customer',
            'title' => 'Domain Expiry Reminder',
            'subject' => 'Domain expiry reminder: {{domain.name}} expires on {{domain.expiry_date}}',
            'body' => "Hi {{client.name}},\n\nThis is a reminder that your domain {{domain.name}} expires on {{domain.expiry_date}}.\n\nRenewal price: {{domain.renewal_price}}\nManaged by: {{company.name}}\n\nPlease contact us if you would like this renewed for you.",
            'admin_recipient_email' => null,
        ],
        [
            'name' => 'SSL Expiry Reminder',
            'slug' => 'ssl-expiry-reminder',
            'trigger_event' => 'ssl_expiring',
            'audience' => 'customer',
            'title' => 'SSL Expiry Reminder',
            'subject' => 'SSL expiry notice for {{ssl.common_name}}',
            'body' => "Hi {{client.name}},\n\nYour SSL certificate for {{ssl.common_name}} expires on {{ssl.expiry_date}}.\n\nCertificate provider: {{ssl.issuer}}\n\nPlease organise a renewal before it expires to avoid service interruptions.",
            'admin_recipient_email' => null,
        ],
        [
            'name' => 'Password Reset Email',
            'slug' => 'password-reset-email',
            'trigger_event' => 'password_reset',
            'audience' => 'customer',
            'title' => 'Password Reset',
            'subject' => 'Reset your {{company.name}} password',
            'body' => "Hi {{client.name}},\n\nWe received a request to reset your password.\n\nReset link: {{auth.reset_link}}\nThis link expires at {{auth.reset_expires_at}}.\n\nIf you did not request this reset, please ignore this email.",
            'admin_recipient_email' => null,
        ],
        [
            'name' => 'Sync Failure Alert',
            'slug' => 'sync-failure-alert',
            'trigger_event' => 'sync_failed',
            'audience' => 'admin',
            'title' => 'Sync Failure Alert',
            'subject' => 'Sync failure: {{sync.job_name}}',
            'body' => "A synchronisation task failed in DomainDash.\n\nJob: {{sync.job_name}}\nFailure time: {{sync.failed_at}}\nError: {{sync.error_message}}\n\nReview details in the admin dashboard.",
            'admin_recipient_email' => 'ops@example.com',
        ],
    ];

    private const VARIABLE_LIBRARY = [
        ['key' => '{{client.name}}', 'description' => 'Client display name.'],
        ['key' => '{{client.email}}', 'description' => 'Primary email for the client contact.'],
        ['key' => '{{company.name}}', 'description' => 'Your configured business name.'],
        ['key' => '{{domain.name}}', 'description' => 'Domain name (for example, example.com).'],
        ['key' => '{{domain.expiry_date}}', 'description' => 'Domain expiry date.'],
        ['key' => '{{domain.renewal_price}}', 'description' => 'Renewal amount for the domain.'],
        ['key' => '{{ssl.common_name}}', 'description' => 'Common Name on the SSL certificate.'],
        ['key' => '{{ssl.expiry_date}}', 'description' => 'SSL certificate expiry date.'],
        ['key' => '{{sync.job_name}}', 'description' => 'Name of the sync task that ran.'],
        ['key' => '{{sync.failed_at}}', 'description' => 'Timestamp when sync failed.'],
        ['key' => '{{sync.error_message}}', 'description' => 'Failure reason returned by the sync task.'],
        ['key' => '{{auth.reset_link}}', 'description' => 'Signed password reset URL for the user.'],
        ['key' => '{{auth.reset_expires_at}}', 'description' => 'Password reset link expiration timestamp.'],
    ];

    private const EVENT_OPTIONS = [
        'domain_expiring' => 'Upcoming domain expiry',
        'ssl_expiring' => 'Upcoming SSL expiry',
        'password_reset' => 'Password reset request',
        'sync_failed' => 'External sync failed',
        'backup_failed' => 'Backup failed',
    ];

    private const AUDIENCE_OPTIONS = ['admin', 'customer'];

    public function index()
    {
        $this->ensureDefaultTemplates();

        return view('admin.notifications.templates', [
            'templates' => EmailTemplate::query()->orderBy('name')->get(),
            'triggers' => NotificationTrigger::query()->with('emailTemplate')->latest()->get(),
            'variableLibrary' => self::VARIABLE_LIBRARY,
            'eventOptions' => self::EVENT_OPTIONS,
            'audienceOptions' => self::AUDIENCE_OPTIONS,
        ]);
    }

    public function updateTemplate(Request $request, EmailTemplate $template)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'audience' => ['required', Rule::in(self::AUDIENCE_OPTIONS)],
            'admin_recipient_email' => ['nullable', 'email', 'max:255', 'required_if:audience,admin'],
        ]);

        $template->update([
            'title' => trim($data['title']),
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'audience' => $data['audience'],
            'admin_recipient_email' => $data['audience'] === 'admin'
                ? trim((string) ($data['admin_recipient_email'] ?? ''))
                : null,
        ]);

        return back()->with('status', 'Template updated.');
    }

    public function storeTrigger(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event_key' => ['required', Rule::in(array_keys(self::EVENT_OPTIONS))],
            'days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'audience' => ['required', Rule::in(self::AUDIENCE_OPTIONS)],
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'admin_create_halo_ticket' => ['nullable', 'boolean'],
            'halo_ticket_board' => ['nullable', 'string', 'max:120'],
            'halo_ticket_type' => ['nullable', 'string', 'max:120'],
        ]);

        $createHaloTicket = (bool) ($data['admin_create_halo_ticket'] ?? false);

        NotificationTrigger::create([
            'name' => trim($data['name']),
            'event_key' => $data['event_key'],
            'days_before' => $data['days_before'] ?? null,
            'audience' => $data['audience'],
            'email_template_id' => (int) $data['email_template_id'],
            'admin_create_halo_ticket' => $data['audience'] === 'admin' ? $createHaloTicket : false,
            'halo_ticket_board' => ($data['audience'] === 'admin' && $createHaloTicket)
                ? trim((string) ($data['halo_ticket_board'] ?? ''))
                : null,
            'halo_ticket_type' => ($data['audience'] === 'admin' && $createHaloTicket)
                ? trim((string) ($data['halo_ticket_type'] ?? ''))
                : null,
        ]);

        return back()->with('status', 'Notification trigger created.');
    }

    private function ensureDefaultTemplates(): void
    {
        foreach (self::DEFAULT_TEMPLATES as $template) {
            EmailTemplate::query()->firstOrCreate(
                ['slug' => $template['slug']],
                [
                    'name' => $template['name'],
                    'trigger_event' => $template['trigger_event'],
                    'audience' => $template['audience'],
                    'title' => $template['title'],
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'admin_recipient_email' => $template['admin_recipient_email'],
                    'is_system' => true,
                ]
            );
        }
    }
}
