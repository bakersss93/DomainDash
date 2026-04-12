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
            'description' => 'Warns customers before a domain reaches expiry.',
            'subject' => 'Domain expiry reminder: {{domain.name}} expires on {{domain.expiry_date}}',
            'body' => "Hi {{client.name}},\n\nThis is a reminder that your domain {{domain.name}} expires on {{domain.expiry_date}}.\n\nRenewal price: {{domain.renewal_price}}\nManaged by: {{company.name}}\n\nPlease contact us if you would like this renewed for you.",
        ],
        'ssl_expiring' => [
            'name' => 'SSL Expiry Reminder',
            'description' => 'Notifies customers when SSL certificates are close to expiring.',
            'subject' => 'SSL expiry notice for {{ssl.common_name}}',
            'body' => "Hi {{client.name}},\n\nYour SSL certificate for {{ssl.common_name}} expires on {{ssl.expiry_date}}.\n\nCertificate provider: {{ssl.issuer}}\n\nPlease organise a renewal before it expires to avoid service interruptions.",
        ],
        'sync_failed' => [
            'name' => 'Sync Failure Alert',
            'description' => 'Alerts internal teams when a synchronisation task fails.',
            'subject' => 'Sync failure: {{sync.job_name}}',
            'body' => "A synchronisation task failed in DomainDash.\n\nJob: {{sync.job_name}}\nFailure time: {{sync.failed_at}}\nError: {{sync.error_message}}\n\nReview details in the admin dashboard.",
        ],
        'backup_failed' => [
            'name' => 'Backup Failure Alert',
            'description' => 'Alerts internal teams when an automated backup fails.',
            'subject' => 'Backup failed on {{backup.failed_at}}',
            'body' => "A scheduled backup failed.\n\nTime: {{backup.failed_at}}\nPath: {{backup.path}}\nError: {{backup.error_message}}\n\nPlease investigate and run a manual backup if needed.",
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
        ['key' => '{{ssl.issuer}}', 'description' => 'SSL issuing authority.'],
        ['key' => '{{sync.job_name}}', 'description' => 'Name of the sync task that ran.'],
        ['key' => '{{sync.failed_at}}', 'description' => 'Timestamp when sync failed.'],
        ['key' => '{{sync.error_message}}', 'description' => 'Failure reason returned by the sync task.'],
        ['key' => '{{backup.failed_at}}', 'description' => 'Timestamp when the backup failed.'],
        ['key' => '{{backup.path}}', 'description' => 'Backup destination path.'],
        ['key' => '{{backup.error_message}}', 'description' => 'Failure reason returned by backup task.'],
        ['key' => '{{app.url}}', 'description' => 'Base URL of your DomainDash installation.'],
    ];

    private const TRIGGER_LIBRARY = [
        'domain_expiring' => [
            'name' => 'Domain expiry notifications',
            'description' => 'Send a reminder before a managed domain expires.',
            'default_template' => 'domain_expiring',
        ],
        'ssl_expiring' => [
            'name' => 'SSL expiry notifications',
            'description' => 'Send a reminder before an SSL certificate expires.',
            'default_template' => 'ssl_expiring',
        ],
        'sync_failed' => [
            'name' => 'Sync failure alerts',
            'description' => 'Send an alert when external sync tasks fail.',
            'default_template' => 'sync_failed',
        ],
        'backup_failed' => [
            'name' => 'Backup failure alerts',
            'description' => 'Send an alert when scheduled backups fail.',
            'default_template' => 'backup_failed',
        ],
    ];

    public function index()
    {
        $config = $this->mergedConfiguration();

        return view('admin.notifications.templates', [
            'templateLibrary' => self::TEMPLATE_LIBRARY,
            'variableLibrary' => self::VARIABLE_LIBRARY,
            'triggerLibrary' => self::TRIGGER_LIBRARY,
            'configuredTemplates' => $config['templates'],
            'configuredTriggers' => $config['triggers'],
        ]);
    }

    public function update(Request $request)
    {
        $templateKeys = array_keys(self::TEMPLATE_LIBRARY);
        $triggerKeys = array_keys(self::TRIGGER_LIBRARY);

        $validated = $request->validate([
            'templates' => ['required', 'array'],
            'templates.*' => ['array'],
            'templates.*.subject' => ['required', 'string', 'max:255'],
            'templates.*.body' => ['required', 'string'],
            'triggers' => ['required', 'array'],
            'triggers.*' => ['array'],
            'triggers.*.enabled' => ['nullable', 'boolean'],
            'triggers.*.template' => ['required', 'string', Rule::in($templateKeys)],
            'triggers.*.recipients' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = $this->mergedConfiguration();

        foreach ($templateKeys as $templateKey) {
            if (!isset($validated['templates'][$templateKey])) {
                continue;
            }

            $templateInput = $validated['templates'][$templateKey];
            $settings['templates'][$templateKey]['subject'] = trim($templateInput['subject']);
            $settings['templates'][$templateKey]['body'] = trim($templateInput['body']);
        }

        foreach ($triggerKeys as $triggerKey) {
            if (!isset($validated['triggers'][$triggerKey])) {
                continue;
            }

            $triggerInput = $validated['triggers'][$triggerKey];
            $settings['triggers'][$triggerKey]['enabled'] = (bool) ($triggerInput['enabled'] ?? false);
            $settings['triggers'][$triggerKey]['template'] = $triggerInput['template'];
            $settings['triggers'][$triggerKey]['recipients'] = trim((string) ($triggerInput['recipients'] ?? ''));
        }

        Setting::put('email_notifications', $settings);

        return back()->with('status', 'Email notification templates updated.');
    }

    private function mergedConfiguration(): array
    {
        $saved = Setting::get('email_notifications', []);
        $templates = [];
        $triggers = [];

        foreach (self::TEMPLATE_LIBRARY as $templateKey => $template) {
            $templates[$templateKey] = [
                'subject' => data_get($saved, "templates.{$templateKey}.subject", $template['subject']),
                'body' => data_get($saved, "templates.{$templateKey}.body", $template['body']),
            ];
        }

        foreach (self::TRIGGER_LIBRARY as $triggerKey => $trigger) {
            $triggers[$triggerKey] = [
                'enabled' => (bool) data_get($saved, "triggers.{$triggerKey}.enabled", true),
                'template' => data_get($saved, "triggers.{$triggerKey}.template", $trigger['default_template']),
                'recipients' => data_get($saved, "triggers.{$triggerKey}.recipients", ''),
            ];
        }

        return [
            'templates' => $templates,
            'triggers' => $triggers,
        ];
    }
}
