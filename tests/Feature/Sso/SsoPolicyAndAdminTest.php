<?php

namespace Tests\Feature\Sso;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SamlConnection;
use App\Models\Setting;
use App\Models\User;
use App\Services\Saml\MetadataImporter;
use App\Services\Saml\SamlException;
use Illuminate\Support\Facades\Http;
use Tests\Support\SamlFixture;

class SsoPolicyAndAdminTest extends SsoTestCase
{
    private function admin(array $extra = []): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()] + $extra);
        $u->assignRole('Administrator');

        return $u;
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Contoso staff', 'type' => 'staff', 'entra_tenant_id' => SamlFixture::TENANT,
            'idp_entity_id' => 'https://sts.windows.net/'.SamlFixture::TENANT.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/'.SamlFixture::TENANT.'/saml2',
            'idp_x509_cert' => $this->idpKey['cert'], 'domains' => "contoso.com\nfabrikam.com",
            'group_role_map' => '11111111-1111-1111-1111-111111111111 = Technician', 'enabled' => '1',
        ];
    }

    // ---- login-method policy (staff) ---------------------------------------------------------------------

    public function test_staff_microsoft_only_blocks_password_login_for_staff_but_not_exceptions_or_clients(): void
    {
        $tech = User::factory()->create(['sso_enabled' => true, 'email' => 'tech@contoso.com']);
        $tech->assignRole('Technician');
        $bg = User::factory()->create(['sso_enabled' => true, 'email' => 'bg@contoso.com', 'password_login_allowed' => true]);
        $bg->assignRole('Administrator');
        $cust = User::factory()->create(['sso_enabled' => true, 'email' => 'cust@acme.com']);
        $cust->assignRole('Customer');

        // Before the policy everyone can use a password.
        $this->post('/login', ['email' => 'tech@contoso.com', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($tech);
        auth()->logout();

        Setting::put('sso', ['staff_login' => 'microsoft_only']);

        // Same generic failure as a wrong password; audited.
        $this->post('/login', ['email' => 'tech@contoso.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $blockedMessage = session('errors')->first('email');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.password.blocked_by_policy')->exists());
        $this->post('/login', ['email' => 'tech@contoso.com', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertSame($blockedMessage, session('errors')->first('email'), 'blocked looks exactly like a wrong password');
        $this->assertGuest();

        $this->post('/login', ['email' => 'bg@contoso.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($bg);
        auth()->logout();

        $this->post('/login', ['email' => 'cust@acme.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($cust);
    }

    public function test_staff_microsoft_only_cannot_be_enabled_without_a_password_admin_or_staff_connection(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.sso.settings'), ['staff_login' => 'microsoft_only', 'client_login' => 'both'])->assertSessionHasErrors('staff_login');

        $admin->forceFill(['password_login_allowed' => true])->save();
        $this->post(route('admin.sso.settings'), ['staff_login' => 'microsoft_only', 'client_login' => 'both'])->assertSessionHasErrors('staff_login'); // no staff connection

        $this->staffConnection();
        $this->post(route('admin.sso.settings'), ['staff_login' => 'microsoft_only', 'client_login' => 'both'])->assertSessionHasNoErrors();

        $this->assertSame('microsoft_only', \App\Services\Auth\LoginPolicy::staffMode());
        $this->assertTrue(AuditLog::where('action', 'settings.login_policy.updated')->exists());
    }

    public function test_last_enabled_connection_and_last_password_admin_are_protected_while_staff_is_microsoft_only(): void
    {
        $admin = $this->admin(['password_login_allowed' => true]);
        $c = $this->staffConnection();
        Setting::put('sso', ['staff_login' => 'microsoft_only']);
        $this->actingAs($admin);

        $this->delete(route('admin.sso.destroy', $c))->assertSessionHasErrors('enabled');
        $this->put(route('admin.sso.update', $c), $this->payload(['enabled' => '0']))->assertSessionHasErrors('enabled');
        $this->assertSame(1, SamlConnection::count());

        // Removing the exception from the only password administrator is refused too.
        $this->put(route('admin.users.update', $admin), [
            'first_name' => 'A', 'last_name' => 'B', 'email' => $admin->email, 'role' => 'Administrator',
            'mfa_preference' => 'enabled', 'is_active' => '1', 'password_login_allowed' => '0',
        ])->assertSessionHasErrors('password_login_allowed');
        $this->assertTrue($admin->fresh()->password_login_allowed);
    }

    public function test_password_exception_can_be_set_by_administrators_only_and_is_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->assignRole('Technician');
        $payload = ['first_name' => 'T', 'last_name' => 'U', 'email' => $user->email, 'role' => 'Technician', 'mfa_preference' => 'enabled', 'is_active' => '1'];

        $this->actingAs($admin)->put(route('admin.users.update', $user), $payload + ['password_login_allowed' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->password_login_allowed);
        $this->assertTrue(AuditLog::where('action', 'user.password_exception.changed')->exists());
        $this->get(route('admin.users.edit', $user))->assertOk()->assertSee('May also use a password')->assertSee('Effective sign-in policy');
    }

    // ---- MFA --------------------------------------------------------------------------------------------

    public function test_sso_session_satisfies_mandatory_mfa_when_connection_trusts_entra(): void
    {
        $c = $this->staffConnection(['default_role' => 'Administrator', 'skip_local_mfa' => true]);

        $this->ssoLogin($c, ['email' => 'root@contoso.com'])->assertRedirect();

        $this->assertSame('saml', session('auth.via'));
        $this->get('/admin/users')->assertOk(); // an Administrator without local MFA would otherwise be bounced to the dashboard
    }

    public function test_local_mfa_challenge_is_required_when_connection_does_not_trust_entra(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician', 'skip_local_mfa' => false]);
        $user = User::factory()->create(['sso_enabled' => true, 'email' => 'mfa@contoso.com', 'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('Technician');

        $this->ssoLogin($c, ['email' => 'mfa@contoso.com'])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
        $this->assertNull(session('auth.via'));
    }

    // ---- admin UI ---------------------------------------------------------------------------------------

    public function test_only_administrators_can_manage_connections(): void
    {
        $c = $this->staffConnection();
        $tech = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $tech->assignRole('Technician');

        $this->get(route('admin.sso.index'))->assertRedirect(route('login'));
        $this->actingAs($tech);
        $this->get(route('admin.sso.index'))->assertForbidden();
        $this->get(route('admin.sso.edit', $c))->assertForbidden();
        $this->post(route('admin.sso.store'), $this->payload())->assertForbidden();
        $this->put(route('admin.sso.update', $c), $this->payload())->assertForbidden();
        $this->delete(route('admin.sso.destroy', $c))->assertForbidden();
        $this->post(route('admin.sso.settings'), ['staff_login' => 'both', 'client_login' => 'both'])->assertForbidden();
        $this->post(route('admin.sso.microsoft'), ['enabled' => '0'])->assertForbidden();
    }

    public function test_admin_can_create_edit_view_and_delete_a_connection_with_audit_trail(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.sso.store'), $this->payload())->assertSessionHasNoErrors();
        $c = SamlConnection::firstOrFail();
        $this->assertSame(['contoso.com', 'fabrikam.com'], $c->domainList());
        $this->assertSame([['group_id' => '11111111-1111-1111-1111-111111111111', 'role' => 'Technician']], $c->group_role_map);
        $this->assertTrue(AuditLog::where('action', 'sso.connection.created')->exists());

        $this->get(route('admin.sso.index'))->assertOk()->assertSee('Contoso staff');
        $page = $this->get(route('admin.sso.edit', $c))->assertOk();
        $page->assertSee($c->acsUrl())->assertSee($c->spEntityId())->assertSee($c->loginUrl());

        $this->put(route('admin.sso.update', $c), $this->payload(['name' => 'Renamed', 'type' => 'client', 'domains' => 'contoso.com']))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('Renamed', $c->name);
        $this->assertSame('staff', $c->type, 'type is immutable');
        $this->assertSame(['contoso.com'], $c->domainList());
        $log = AuditLog::where('action', 'sso.connection.updated')->firstOrFail();
        $this->assertSame('Contoso staff', $log->old_values['name']);

        $this->post(route('admin.sso.check', $c))->assertRedirect();
        $this->delete(route('admin.sso.destroy', $c))->assertRedirect(route('admin.sso.index'));
        $this->assertSame(0, SamlConnection::count());
        $this->assertTrue(AuditLog::where('action', 'sso.connection.deleted')->exists());
    }

    public function test_saml_is_staff_only_a_client_type_cannot_be_created(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.sso.store'), $this->payload(['type' => 'client']))->assertSessionHasNoErrors();
        $this->assertSame('staff', SamlConnection::firstOrFail()->type);
        $this->get(route('admin.sso.create', ['type' => 'client']))->assertOk()->assertDontSee('client_id');
        $this->get(route('admin.sso.index'))->assertOk()->assertDontSee('Client connection');
    }

    public function test_mapping_form_lists_staff_roles_dynamically_and_never_customer_roles(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('Auditor')->givePermissionTo('users.manage'); // staff-class: holds a staff-only permission
        \Spatie\Permission\Models\Role::findOrCreate('Viewer')->givePermissionTo('domains.view'); // custom customer-class role
        $this->actingAs($this->admin());

        $page = $this->get(route('admin.sso.create'))->assertOk();
        $page->assertSee('group_map[Technician]', false)->assertSee('group_map[Administrator]', false)->assertSee('group_map[Auditor]', false);
        $page->assertDontSee('group_map[Customer]', false)->assertDontSee('group_map[Viewer]', false);

        $payload = $this->payload(['group_role_map' => null, 'group_map' => ['Administrator' => '', 'Auditor' => "AAAAAAAA-0000-0000-0000-000000000001\n11111111-1111-1111-1111-111111111111"]]);
        $this->post(route('admin.sso.store'), $payload)->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([
            ['group_id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'role' => 'Auditor'],
            ['group_id' => '11111111-1111-1111-1111-111111111111', 'role' => 'Auditor'],
        ], SamlConnection::firstOrFail()->group_role_map);

        // A customer role can never be mapped.
        $this->post(route('admin.sso.store'), $this->payload(['domains' => 'other.org', 'group_map' => ['Customer' => '22222222-2222-2222-2222-222222222222']]))->assertSessionHasErrors('group_role_map');
    }

    public function test_tenant_id_must_match_entity_id_and_urls_must_be_safe(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.sso.store'), $this->payload(['entra_tenant_id' => '99999999-9999-9999-9999-999999999999']))->assertSessionHasErrors('idp_entity_id');
        $this->post(route('admin.sso.store'), $this->payload(['idp_sso_url' => 'http://login.example.com/saml']))->assertSessionHasErrors('idp_sso_url');
        $this->post(route('admin.sso.store'), $this->payload(['idp_sso_url' => 'https://169.254.169.254/saml']))->assertSessionHasErrors('idp_sso_url');
        $this->post(route('admin.sso.store'), $this->payload(['idp_x509_cert' => 'not a cert']))->assertSessionHasErrors('idp_x509_cert');
        $this->post(route('admin.sso.store'), $this->payload(['domains' => '']))->assertSessionHasErrors('domains'); // enabled needs domains
        $this->assertSame(0, SamlConnection::count());
    }


    // ---- metadata import --------------------------------------------------------------------------------

    private function metadataXml(array $key1, ?array $key2 = null): string
    {
        $kd = fn ($c) => '<KeyDescriptor use="signing"><KeyInfo xmlns="http://www.w3.org/2000/09/xmldsig#"><X509Data><X509Certificate>'.$c['cert'].'</X509Certificate></X509Data></KeyInfo></KeyDescriptor>';

        return '<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://sts.windows.net/'.SamlFixture::TENANT.'/">'
            .'<IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .$kd($key1).($key2 ? $kd($key2) : '')
            .'<SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://login.microsoftonline.com/'.SamlFixture::TENANT.'/saml2"/>'
            .'<SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://login.microsoftonline.com/'.SamlFixture::TENANT.'/saml2"/>'
            .'</IDPSSODescriptor></EntityDescriptor>';
    }

    public function test_metadata_xml_import_extracts_endpoints_tenant_and_both_certificates(): void
    {
        $k2 = SamlFixture::keyPair('second');

        $m = app(MetadataImporter::class)->fromXml($this->metadataXml($this->idpKey, $k2));

        $this->assertSame('https://sts.windows.net/'.SamlFixture::TENANT.'/', $m['idp_entity_id']);
        $this->assertSame(SamlFixture::TENANT, $m['entra_tenant_id']);
        $this->assertSame($this->idpKey['cert'], $m['idp_x509_cert']);
        $this->assertSame($k2['cert'], $m['idp_x509_cert_2']);
    }

    public function test_metadata_xml_with_external_entities_is_refused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="&xxe;"/>';

        $this->expectException(SamlException::class);
        app(MetadataImporter::class)->fromXml($xml);
    }

    public function test_metadata_import_via_admin_form_fills_the_idp_fields(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.sso.store'), $this->payload([
            'idp_entity_id' => '', 'idp_sso_url' => '', 'idp_x509_cert' => '', 'entra_tenant_id' => '',
            'metadata_xml' => $this->metadataXml($this->idpKey),
        ]))->assertSessionHasNoErrors();

        $c = SamlConnection::firstOrFail();
        $this->assertSame(SamlFixture::TENANT, $c->entra_tenant_id);
        $this->assertSame($this->idpKey['cert'], $c->idp_x509_cert);
    }

    public function test_metadata_url_must_be_https_and_public(): void
    {
        Http::fake(['*' => Http::response('<x/>')]);
        $importer = app(MetadataImporter::class);

        foreach (['http://login.microsoftonline.com/m.xml', 'https://127.0.0.1/m.xml', 'https://10.0.0.5/m.xml', 'https://localhost/m.xml', 'https://user:pw@login.microsoftonline.com/m.xml'] as $url) {
            try {
                $importer->fromUrl($url);
                $this->fail("{$url} should have been refused");
            } catch (SamlException $e) {
                $this->assertSame('bad_metadata_url', $e->reason, $url);
            }
        }
        Http::assertNothingSent();
    }

    public function test_metadata_url_fetch_does_not_follow_redirects_and_imports_valid_xml(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/ok.xml' => Http::response($this->metadataXml($this->idpKey)),
            'https://login.microsoftonline.com/redir.xml' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        ]);
        $importer = app(MetadataImporter::class);

        $this->assertSame($this->idpKey['cert'], $importer->fromUrl('https://login.microsoftonline.com/ok.xml')['idp_x509_cert']);

        $this->expectException(SamlException::class);
        $importer->fromUrl('https://login.microsoftonline.com/redir.xml');
    }
}
