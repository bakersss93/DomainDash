<?php

/*
|--------------------------------------------------------------------------
| Support > Help (knowledge base)
|--------------------------------------------------------------------------
| Articles are Markdown files: {path}/{audience}/{category}/{slug}.md with YAML front matter
| (title, summary, category, order, audience, permission, related). See resources/help/README.md.
*/
return [
    // Where the Markdown articles live. Tests point this at tests/Fixtures/help.
    'path' => resource_path('help'),

    // The permission that gates EVERY article of audience "integration" (staff only, see StaffPermissions).
    'integrations_permission' => 'help.integrations.view',

    // Display name and order of categories (the directory name is the category slug). Unknown slugs are
    // shown with a title-cased name after these.
    'categories' => [
        'getting-started' => ['title' => 'Getting started', 'summary' => 'Signing in, multi-factor authentication and your account.'],
        'domains-dns' => ['title' => 'Domains and DNS', 'summary' => 'Your domains and how to manage their DNS records.'],
        'services' => ['title' => 'Hosting, SSL and internet', 'summary' => 'See the services you have with us and test your connection.'],
        'support' => ['title' => 'Support and notifications', 'summary' => 'Raise tickets and keep track of alerts.'],
        'integrations' => ['title' => 'Integrations', 'summary' => 'Connect Synergy Wholesale, HaloPSA, IT Glue, Vocus and IP2WHOIS.'],
        'security' => ['title' => 'Security and access', 'summary' => 'Single sign-on, users, roles, API keys, audit logs and MFA.'],
        'platform' => ['title' => 'Platform and operations', 'summary' => 'Email, backups, HTTPS and scheduled syncs.'],
    ],
];
