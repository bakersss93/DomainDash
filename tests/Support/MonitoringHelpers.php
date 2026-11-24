<?php

namespace Tests\Support;

use App\Models\IntegrationRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Monitoring\MonitoringSettings;
use Spatie\Permission\Models\Role;

trait MonitoringHelpers
{
    protected function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('Administrator');

        return $user;
    }

    protected function technician(): User
    {
        Role::findOrCreate('Technician', 'web');
        $user = User::factory()->create();
        $user->assignRole('Technician');

        return $user;
    }

    /** Persist monitoring settings (and refresh the scoped settings instance). */
    protected function monitoring(array $values): void
    {
        $current = Setting::get('audit', []);
        Setting::put('audit', array_merge(is_array($current) ? $current : [], $values));
        app(MonitoringSettings::class)->flush();
    }

    protected function makeRequest(array $overrides = []): IntegrationRequest
    {
        return IntegrationRequest::create($overrides + [
            'direction' => 'outbound',
            'provider' => 'halo',
            'method' => 'GET',
            'url' => 'https://halo.example.test/api/Asset',
            'request_headers' => ['Authorization' => 'Bearer [REDACTED]', 'x-api-key' => 'ITG.7474…'],
            'request_body' => json_encode(['name' => 'Example'], JSON_PRETTY_PRINT),
            'response_status' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
            'response_body' => json_encode(['id' => 1], JSON_PRETTY_PRINT),
            'duration_ms' => 42,
            'reference' => 'example.com',
            'is_error' => false,
            'created_at' => now(),
        ]);
    }
}
