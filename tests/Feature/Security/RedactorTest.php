<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Redactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_redacts_nested_secret_keys_case_insensitively(): void
    {
        $out = Redactor::redact([
            'halo' => ['API_KEY' => 'a', 'tenant' => 't', 'client_secret' => 'c'],
            'smtp' => ['password' => 'p'],
            'list' => [['token' => 'x'], ['name' => 'ok']],
            'key' => 'k',
            'x-api-key' => 'h',
        ]);

        $this->assertSame('[REDACTED]', $out['halo']['API_KEY']);
        $this->assertSame('t', $out['halo']['tenant']);
        $this->assertSame('[REDACTED]', $out['halo']['client_secret']);
        $this->assertSame('[REDACTED]', $out['smtp']['password']);
        $this->assertSame('[REDACTED]', $out['list'][0]['token']);
        $this->assertSame('ok', $out['list'][1]['name']);
        $this->assertSame('[REDACTED]', $out['key']);
        $this->assertSame('[REDACTED]', $out['x-api-key']);
    }

    public function test_audit_logger_never_persists_secrets(): void
    {
        $this->actingAs(User::factory()->create());

        AuditLogger::logSystem('settings.update', 'x', ['service' => 'settings'], [
            'old_values' => ['smtp' => ['password' => 'old-secret']],
            'new_values' => ['smtp' => ['password' => 'new-secret', 'host' => 'mail.example.com']],
        ]);

        $row = AuditLog::latest('id')->first();
        $json = json_encode([$row->old_values, $row->new_values]);
        $this->assertStringNotContainsString('old-secret', $json);
        $this->assertStringNotContainsString('new-secret', $json);
        $this->assertSame('mail.example.com', $row->new_values['smtp']['host']);
    }

    public function test_preserve_secrets_keeps_stored_value_for_placeholder_blank_or_missing(): void
    {
        $current = ['api_key' => 'stored', 'tenant' => 'a', 'nested' => ['password' => 'stored-pw']];

        $kept = Redactor::preserveSecrets(['api_key' => '********', 'tenant' => 'b', 'nested' => ['password' => '']], $current);
        $this->assertSame('stored', $kept['api_key']);
        $this->assertSame('b', $kept['tenant']);
        $this->assertSame('stored-pw', $kept['nested']['password']);

        $changed = Redactor::preserveSecrets(['api_key' => 'fresh'], $current);
        $this->assertSame('fresh', $changed['api_key']);
    }

    public function test_form_mask_hides_secrets(): void
    {
        $this->assertSame('********', Redactor::maskForForm(['itglue' => ['api_key' => 'real']])['itglue']['api_key']);
    }
}
