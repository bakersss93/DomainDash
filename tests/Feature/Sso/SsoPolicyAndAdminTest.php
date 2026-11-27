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
            'name' => 'Jargon staff', 'type' => 'staff', 'entra_tenant_id' => SamlFixture::TENANT,
            'idp_entity_id' => 'https://sts.windows.net/'.SamlFixture::TENANT.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/'.SamlFixture::TENANT.'/saml2',
            'idp_x509_cert' => $this->idpKey['cert'], 'domains' => "contoso.com\nfabrikam.com",
            'group_role_map' => '11111111-1111-1111-1111-111111111111 = Technician', 'enabled' => '1',
        ];
    }

    // ---- enforce SSO for staff / break-glass ------------------------------------------------------------

    public function test_enforce_sso_blocks_staff_password_login_but_not_break_glass_or_customers(): void
    {
        $tech = User::factory()->create(['email' => 'tech@contoso.com']);
        $tech->assignRole('Technician');
        $bg = User::factory()->create(['email' => 'bg@contoso.com', 'is_break_glass' => true]);
        $bg->assignRole('Administrator');
        $cust = User::factory()->create(['email' => 'cust@acme.com']);
        $cust->assignRole('Customer');

        // Before enforcement everyone can use a password.
        $this->post('/login', ['email' => 'tech@contoso.com', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($tech);
        auth()->logout();

        Setting::put('sso', ['enforce_staff' => true]);

        $this->post('/login', ['email' => 'tech@contoso.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'bg@contoso.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($bg);
        auth()->logout();

        $this->post('/login', ['email' => 'cust@acme.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($cust);
    }

    public function test_enforcement_cannot_be_enabled_without_a_break_glass_admin_or_staff_connection(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.sso.settings'), ['enforce_staff' => '1'])->assertSessionHasErrors('enforce_staff');

        $this->post(route('admin.sso.settings'), ['enforce_staff' => '1', 'break_glass' => [$admin->id]])->assertSessionHasErrors('enforce_staff'); // no staff connection

        $this->staffConnection();
        $this->post(route('admin.sso.settings'), ['enforce_staff' => '1', 'break_glass' => [$admin->id]])->assertSessionHasNoErrors();

        $this->assertTrue(SsoPolicyProbe::enforced());
        $this->assertTrue($admin->fresh()->is_break_glass);
        $this->assertTrue(AuditLog::where('action', 'sso.settings.updated')->exists());
    }

    public function test_sso_linked_users_cannot_be_break_glass(): void
    {
        $admin = $this->admin();
        $linked = User::factory()->create(['saml_connection_id' => $this->staffConnection()->id, 'saml_object_id' => 'x']);
        $linked->assignRole('Administrator');

        $this->actingAs($admin)->post(route('admin.sso.settings'), ['break_glass' => [$linked->id]])->assertSessionHasErrors('break_glass');
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
        $user = User::factory()->create(['email' => 'mfa@contoso.com', 'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
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
        $this->post(route('admin.sso.settings'), ['enforce_staff' => '0'])->assertForbidden();
    }

    public function test_admin_can_create_edit_view_and_delete_a_connection_with_audit_trail(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.sso.store'), $this->payload())->assertSessionHasNoErrors();
        $c = SamlConnection::firstOrFail();
        $this->assertSame(['contoso.com', 'fabrikam.com'], $c->domainList());
        $this->assertSame([['group_id' => '11111111-1111-1111-1111-111111111111', 'role' => 'Technician']], $c->group_role_map);
        $this->assertTrue(AuditLog::where('action', 'sso.connection.created')->exists());

        $this->get(route('admin.sso.index'))->assertOk()->assertSee('Jargon staff');
        $page = $this->get(route('admin.sso.edit', $c))->assertOk();
        $page->assertSee($c->acsUrl())->assertSee($c->spEntityId())->assertSee($c->loginUrl());

        $this->put(route('admin.sso.update', $c), $this->payload(['name' => 'Renamed', 'type' => 'client', 'domains' => 'contoso.com']))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('Renamed', $c->name);
        $this->assertSame('staff', $c->type, 'type is immutable');
        $this->assertSame(['contoso.com'], $c->domainList());
        $log = AuditLog::where('action', 'sso.connection.updated')->firstOrFail();
        $this->assertSame('Jargon staff', $log->old_values['name']);

        $this->post(route('admin.sso.check', $c))->assertRedirect();
        $this->delete(route('admin.sso.destroy', $c))->assertRedirect(route('admin.sso.index'));
        $this->assertSame(0, SamlConnection::count());
        $this->assertTrue(AuditLog::where('action', 'sso.connection.deleted')->exists());
    }

    public function test_client_connection_is_created_from_the_client_page_prefilled(): void
    {
        $client = Client::create(['business_name' => 'Acme Pty Ltd']);
        $this->actingAs($this->admin());

        $this->get(route('admin.clients.edit', $client))->assertOk()->assertSee('Add SSO connection')->assertSee('client_id='.$client->id);
        $this->get(route('admin.sso.create', ['type' => 'client', 'client_id' => $client->id]))->assertOk()->assertSee('Acme Pty Ltd');
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

    public function test_login_page_offers_microsoft_sign_in_only_when_a_connection_is_enabled(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Sign in with Microsoft');

        $this->staffConnection();

        $this->get('/login')->assertOk()->assertSee('Sign in with Microsoft');
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

final class SsoPolicyProbe
{
    public static function enforced(): bool
    {
        return \App\Services\Saml\SsoPolicy::enforceStaff();
    }
}
