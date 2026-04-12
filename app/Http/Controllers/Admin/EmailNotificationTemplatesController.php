<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailNotificationTemplatesController extends Controller
{
    private const TEMPLATE_LIBRARY = [
        'domain_expiring' => [
            'name' => 'Domain Expiry Reminder',
            'trigger' => 'Domain nearing expiry',
            'group' => 'customer',
            'title' => 'Domain Expiry Reminder',
            'subject' => 'Domain expiry reminder: {{domain.name}} expires on {{domain.expiry_date}}',
            'body' => "Hi {{client.name}},\n\nThis is a reminder that your domain {{domain.name}} expires on {{domain.expiry_date}}.\n\nRenewal price: {{domain.renewal_price}}\nManaged by: {{company.name}}\n\nPlease contact us if you would like this renewed for you.",
            'recipient_email' => '',
        ],
        'ssl_expiring' => [
            'name' => 'SSL Expiry Reminder',
            'trigger' => 'SSL certificate nearing expiry',
            'group' => 'customer',
            'title' => 'SSL Expiry Reminder',
            'subject' => 'SSL expiry notice for {{ssl.common_name}}',
            'body' => "Hi {{client.name}},\n\nYour SSL certificate for {{ssl.common_name}} expires on {{ssl.expiry_date}}.\n\nCertificate provider: {{ssl.issuer}}\n\nPlease organise a renewal before it expires to avoid service interruptions.",
            'recipient_email' => '',
        ],
        'password_reset' => [
            'name' => 'Password Reset Email',
            'trigger' => 'User password reset requested',
            'group' => 'customer',
            'title' => 'Password Reset',
            'subject' => 'Reset your {{company.name}} password',
            'body' => "Hi {{client.name}},\n\nWe received a request to reset your password.\n\nReset link: {{auth.reset_link}}\nThis link expires at {{auth.reset_expires_at}}.\n\nIf you did not request this reset, please ignore this email.",
            'recipient_email' => '',
        ],
        'sync_failed' => [
            'name' => 'Sync Failure Alert',
            'trigger' => 'External sync failed',
            'group' => 'admin',
            'title' => 'Sync Failure Alert',
            'subject' => 'Sync failure: {{sync.job_name}}',
            'body' => "A synchronisation task failed in DomainDash.\n\nJob: {{sync.job_name}}\nFailure time: {{sync.failed_at}}\nError: {{sync.error_message}}\n\nReview details in the admin dashboard.",
            'recipient_email' => 'ops@example.com',
        ],
        'backup_failed' => [
            'name' => 'Backup Failure Alert',
            'trigger' => 'Scheduled backup failed',
            'group' => 'admin',
            'title' => 'Backup Failure Alert',
            'subject' => 'Backup failed on {{backup.failed_at}}',
            'body' => "A scheduled backup failed.\n\nTime: {{backup.failed_at}}\nPath: {{backup.path}}\nError: {{backup.error_message}}\n\nPlease investigate and run a manual backup if needed.",
            'recipient_email' => 'ops@example.com',
        ],
    ];

    private const GROUP_OPTIONS = ['admin', 'customer'];

    private const VARIABLE_LIBRARY = [
        ['key' => '{{client.name}}', 'description' => 'Client display name.'],
        ['key' => '{{client.email}}', 'description' => 'Primary email for the client contact.'],
        ['key' => '{{company.name}}', 'description' => 'Your configured business name.'],
        ['key' => '{{domain.name}}', 'description' => 'Domain name (for example, example.com).'],
        ['key' => '{{domain.expiry_date}}', 'description' => 'Domain expiry date.'],
        ['key' => '{{domain.renewal_price}}', 'description' => 'Renewal amount for the domain.'],
        ['key' => '{{ssl.common_name}}', 'description' => 'Common Name on the SSL certificate.'],
        ['key' => '{{ssl.expiry_date}}', 'description' => 'SSL certificate expiry date.'],
        ['key' => '{{ssl.issuer}}', 'description' => 'SSL issuing authority.'],
        ['key' => '{{sync.job_name}}', 'description' => 'Name of the sync task that ran.'],
        ['key' => '{{sync.failed_at}}', 'description' => 'Timestamp when sync failed.'],
        ['key' => '{{sync.error_message}}', 'description' => 'Failure reason returned by the sync task.'],
        ['key' => '{{backup.failed_at}}', 'description' => 'Timestamp when the backup failed.'],
        ['key' => '{{backup.path}}', 'description' => 'Backup destination path.'],
        ['key' => '{{backup.error_message}}', 'description' => 'Failure reason returned by backup task.'],
        ['key' => '{{auth.reset_link}}', 'description' => 'Signed password reset URL for the user.'],
        ['key' => '{{auth.reset_expires_at}}', 'description' => 'Password reset link expiration timestamp.'],
        ['key' => '{{app.url}}', 'description' => 'Base URL of your DomainDash installation.'],
    ];

    public function index()
    {
        return view('admin.notifications.templates', [
            'templateLibrary' => self::TEMPLATE_LIBRARY,
            'groupOptions' => self::GROUP_OPTIONS,
            'variableLibrary' => self::VARIABLE_LIBRARY,
            'configuredTemplates' => $this->mergedConfiguration(),
        ]);
    }

    public function update(Request $request)
    {
        $templateKeys = array_keys(self::TEMPLATE_LIBRARY);

        $validated = $request->validate([
            'templates' => ['required', 'array'],
            'templates.*' => ['array'],
            'templates.*.title' => ['required', 'string', 'max:120'],
            'templates.*.subject' => ['required', 'string', 'max:255'],
            'templates.*.body' => ['required', 'string'],
            'templates.*.group' => ['required', 'string', Rule::in(self::GROUP_OPTIONS)],
            'templates.*.recipient_email' => ['nullable', 'email', 'max:255', 'required_if:templates.*.group,admin'],
        ]);

        $settings = $this->mergedConfiguration();

        foreach ($templateKeys as $templateKey) {
            if (!isset($validated['templates'][$templateKey])) {
                continue;
            }

            $input = $validated['templates'][$templateKey];
            $group = $input['group'];

            $settings[$templateKey]['title'] = trim($input['title']);
            $settings[$templateKey]['subject'] = trim($input['subject']);
            $settings[$templateKey]['body'] = trim($input['body']);
            $settings[$templateKey]['group'] = $group;
            $settings[$templateKey]['recipient_email'] = $group === 'admin'
                ? trim((string) ($input['recipient_email'] ?? ''))
                : '';
        }

        Setting::put('email_notifications', ['templates' => $settings]);

        return back()->with('status', 'Email notification templates updated.');
    }

    private function mergedConfiguration(): array
    {
        $saved = Setting::get('email_notifications', []);
        $templates = [];

        foreach (self::TEMPLATE_LIBRARY as $templateKey => $template) {
            $group = data_get($saved, "templates.{$templateKey}.group", $template['group']);
            if (!in_array($group, self::GROUP_OPTIONS, true)) {
                $group = $template['group'];
            }

            $templates[$templateKey] = [
                'key' => $templateKey,
                'name' => $template['name'],
                'trigger' => $template['trigger'],
                'title' => data_get($saved, "templates.{$templateKey}.title", $template['title']),
                'subject' => data_get($saved, "templates.{$templateKey}.subject", $template['subject']),
                'body' => data_get($saved, "templates.{$templateKey}.body", $template['body']),
                'group' => $group,
                'recipient_email' => data_get($saved, "templates.{$templateKey}.recipient_email", $template['recipient_email']),
            ];
        }

        return $templates;
    }
}
