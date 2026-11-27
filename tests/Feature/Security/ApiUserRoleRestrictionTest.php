<?php

namespace Tests\Feature\Security;

use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiUserRoleRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Administrator', 'Technician', 'Customer'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function key(array $scopes): string
    {
        $gen = ApiKey::generate();
        ApiKey::create([
            'name' => 'test', 'key_prefix' => $gen['prefix'], 'key_hash' => $gen['hash'],
            'rate_limit_per_hour' => 1000, 'scopes' => $scopes, 'active' => true,
        ]);

        return $gen['plain'];
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'New User', 'email' => 'new@example.com',
            'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x',
        ], $extra);
    }

    public function test_users_write_key_cannot_create_administrator(): void
    {
        $plain = $this->key(['users.write']);

        $this->postJson('/api/v1/users', $this->payload(['role' => 'Administrator']), ['x-api-key' => $plain])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_unknown_role_is_rejected_and_allowed_role_enforces_mfa(): void
    {
        $plain = $this->key(['users.write']);

        $this->postJson('/api/v1/users', $this->payload(['role' => 'Nope']), ['x-api-key' => $plain])->assertStatus(422);

        $this->postJson('/api/v1/users', $this->payload(['role' => 'Technician']), ['x-api-key' => $plain])->assertCreated();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Technician'));
        $this->assertSame('enforced', $user->mfa_preference);
    }

    public function test_users_write_key_cannot_promote_or_delete_administrators(): void
    {
        $plain = $this->key(['users.write']);
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $victim = User::factory()->create();
        $victim->assignRole('Customer');

        $this->putJson("/api/v1/users/{$victim->id}", ['role' => 'Administrator'], ['x-api-key' => $plain])->assertStatus(422);
        $this->putJson("/api/v1/users/{$admin->id}", ['name' => 'x'], ['x-api-key' => $plain])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$admin->id}", [], ['x-api-key' => $plain])->assertForbidden();
        $this->assertTrue($admin->fresh()->hasRole('Administrator'));
    }

    public function test_explicit_admin_scope_allows_administrator_but_not_last_admin_deletion(): void
    {
        $plain = $this->key(['users.write', 'users.admin']);

        $this->postJson('/api/v1/users', $this->payload(['role' => 'Administrator']), ['x-api-key' => $plain])->assertCreated();
        $admin = User::where('email', 'new@example.com')->firstOrFail();

        $this->deleteJson("/api/v1/users/{$admin->id}", [], ['x-api-key' => $plain])->assertStatus(422);
    }

    public function test_key_without_scopes_has_no_access_and_legacy_wildcard_skips_sensitive_scopes(): void
    {
        $none = $this->key([]);
        $this->getJson('/api/v1/domains', ['x-api-key' => $none])->assertForbidden();

        $legacy = $this->key(['read']);
        $this->getJson('/api/v1/domains', ['x-api-key' => $legacy])->assertOk();
        $this->getJson('/api/v1/users', ['x-api-key' => $legacy])->assertForbidden();
    }

    public function test_invalid_key_is_rejected_and_unknown_prefix_does_not_scan(): void
    {
        $this->getJson('/api/v1/domains', ['x-api-key' => 'deadbeef0000.' . str_repeat('a', 48)])->assertUnauthorized();
        $this->getJson('/api/v1/domains')->assertUnauthorized();
    }

    public function test_legacy_bcrypt_key_still_works_and_is_upgraded(): void
    {
        $plain = bin2hex(random_bytes(24));
        $key = ApiKey::create([
            'name' => 'legacy', 'key_hash' => password_hash($plain, PASSWORD_BCRYPT),
            'rate_limit_per_hour' => 100, 'scopes' => ['domains.read'], 'active' => true,
        ]);

        $this->getJson('/api/v1/domains', ['x-api-key' => $plain])->assertOk();

        $key->refresh();
        $this->assertSame(substr($plain, 0, 12), $key->key_prefix);
        $this->assertSame(hash('sha256', $plain), $key->key_hash);
        $this->getJson('/api/v1/domains', ['x-api-key' => $plain])->assertOk();
    }

    public function test_client_domains_endpoint_is_scoped_to_the_client(): void
    {
        $plain = $this->key(['clients.read']);
        $a = Client::create(['business_name' => 'A']);
        $b = Client::create(['business_name' => 'B']);
        Domain::create(['name' => 'a.test', 'client_id' => $a->id]);
        Domain::create(['name' => 'b.test', 'client_id' => $b->id]);

        $res = $this->getJson("/api/v1/clients/{$a->id}/domains", ['x-api-key' => $plain])->assertOk();
        $this->assertSame(['a.test'], collect($res->json('data'))->pluck('name')->all());
    }

    public function test_auth_code_requires_separate_scope(): void
    {
        $plain = $this->key(['domains.read']);
        $d = Domain::create(['name' => 'x.test']);

        $this->getJson("/api/v1/domains/{$d->id}/auth-code", ['x-api-key' => $plain])->assertForbidden();
    }

    public function test_validation_errors_are_json_without_accept_header(): void
    {
        $plain = $this->key(['users.write']);

        $this->post('/api/v1/users', [], ['x-api-key' => $plain])->assertStatus(422)->assertJsonStructure(['errors']);
    }
}
