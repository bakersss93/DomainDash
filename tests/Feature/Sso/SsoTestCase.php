<?php

namespace Tests\Feature\Sso;

use App\Models\SamlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SamlFixture;
use Tests\TestCase;

abstract class SsoTestCase extends TestCase
{
    use RefreshDatabase;

    protected array $idpKey;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['settings.manage', 'users.manage', 'domains.view', 'tickets.create'] as $p) {
            Permission::findOrCreate($p);
        }
        Role::findOrCreate('Administrator')->syncPermissions(['settings.manage', 'users.manage', 'domains.view']);
        Role::findOrCreate('Technician')->syncPermissions(['domains.view']);
        Role::findOrCreate('Customer')->syncPermissions(['domains.view', 'tickets.create']);

        $this->idpKey = SamlFixture::keyPair();
    }

    protected function staffConnection(array $attrs = [], array $domains = ['contoso.com']): SamlConnection
    {
        return SamlFixture::connection($attrs + ['type' => 'staff'], $domains, $this->idpKey);
    }

    protected function clientConnection(array $attrs = [], array $domains = ['acme.com']): SamlConnection
    {
        return SamlFixture::connection($attrs + ['type' => 'client'], $domains, $this->idpKey);
    }

    /** Start SP-initiated login and return the stored AuthnRequest ID. */
    protected function startLogin(SamlConnection $c): string
    {
        $this->get($c->loginUrl())->assertRedirect();

        return (string) DB::table('saml_pending_requests')->where('saml_connection_id', $c->id)->orderByDesc('id')->value('request_id');
    }

    /** Full round trip: start login, craft a response, post it to the ACS. */
    protected function ssoLogin(SamlConnection $c, array $assertion = [], ?array $key = null)
    {
        $id = $assertion['inResponseTo'] ?? $this->startLogin($c);
        $b64 = SamlFixture::response($c, $key ?? $this->idpKey, ['inResponseTo' => $id] + $assertion);

        return $this->post($c->acsUrl(), ['SAMLResponse' => $b64]);
    }
}
