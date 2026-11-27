<?php

namespace Tests\Feature\Sso;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\SamlFixture;

class SamlAssertionValidationTest extends SsoTestCase
{
    private function assertDenied($response, ?string $reason = null): void
    {
        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'alice@contoso.com')->count(), 'no user may be created on a refused assertion');
        if ($reason) {
            $this->assertTrue(
                AuditLog::query()->where('action', 'sso.login.failed')->get()->contains(fn ($l) => ($l->context['reason'] ?? null) === $reason),
                "expected audit failure reason {$reason}"
            );
        }
    }

    public function test_valid_signed_assertion_logs_in_and_creates_user(): void
    {
        $c = $this->staffConnection(['group_role_map' => [['group_id' => 'aaaaaaaa-1111-1111-1111-111111111111', 'role' => 'Technician']]]);

        $this->ssoLogin($c, ['groups' => ['aaaaaaaa-1111-1111-1111-111111111111']])->assertRedirect();

        $this->assertAuthenticated();
        $user = User::where('email', 'alice@contoso.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Technician'));
        $this->assertSame($c->id, $user->saml_connection_id);
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000001', $user->saml_object_id);
        $this->assertSame('saml', session('auth.via'));
        $this->assertTrue(AuditLog::where('action', 'sso.login.success')->exists());
    }

    public function test_unsigned_assertion_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['sign' => false]), 'invalid_response');
    }

    public function test_assertion_signed_with_wrong_key_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $attacker = SamlFixture::keyPair('attacker');

        $this->assertDenied($this->ssoLogin($c, [], $attacker), 'invalid_response');
    }

    public function test_second_certificate_supports_rollover(): void
    {
        $new = SamlFixture::keyPair('rolled');
        $c = $this->staffConnection(['default_role' => 'Technician', 'idp_x509_cert_2' => $new['cert']]);

        $this->ssoLogin($c, [], $new)->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_tampered_assertion_after_signing_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $id = $this->startLogin($c);
        $xml = base64_decode(SamlFixture::response($c, $this->idpKey, ['inResponseTo' => $id, 'email' => 'alice@contoso.com']));
        $xml = str_replace('alice@contoso.com', 'admin@contoso.com', $xml);

        $this->assertDenied($this->post($c->acsUrl(), ['SAMLResponse' => base64_encode($xml)]), 'invalid_response');
        $this->assertSame(0, User::where('email', 'admin@contoso.com')->count());
    }

    public function test_expired_assertion_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        // Older than the library's 180s tolerance and our 60s one.
        $this->assertDenied($this->ssoLogin($c, ['notBefore' => -900, 'notOnOrAfter' => -600]), 'invalid_response');
    }

    public function test_assertion_expired_within_library_tolerance_is_still_rejected_by_our_skew_limit(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        // 120s past NotOnOrAfter: the library alone (180s drift) would accept; we allow only 60s.
        $this->assertDenied($this->ssoLogin($c, ['notBefore' => -400, 'notOnOrAfter' => -120]), 'expired');
    }

    public function test_replayed_assertion_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $id = $this->startLogin($c);
        $b64 = SamlFixture::response($c, $this->idpKey, ['inResponseTo' => $id]);

        $this->post($c->acsUrl(), ['SAMLResponse' => $b64])->assertRedirect();
        $this->assertAuthenticated();
        auth()->logout();

        // Same bytes again (the AuthnRequest ID is single use as well, so either guard stops it).
        $this->post($c->acsUrl(), ['SAMLResponse' => $b64])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_replay_store_blocks_same_assertion_id_under_a_fresh_request(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $first = SamlFixture::response($c, $this->idpKey, ['inResponseTo' => $this->startLogin($c), 'assertionId' => '_fixed-assertion-id']);
        $this->post($c->acsUrl(), ['SAMLResponse' => $first])->assertRedirect();
        auth()->logout();
        User::query()->delete();
        $this->assertSame(1, DB::table('saml_assertion_replays')->count());

        $second = SamlFixture::response($c, $this->idpKey, ['inResponseTo' => $this->startLogin($c), 'assertionId' => '_fixed-assertion-id']);
        $this->assertDenied($this->post($c->acsUrl(), ['SAMLResponse' => $second]), 'replay');
    }

    public function test_in_response_to_must_match_a_stored_request(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['inResponseTo' => 'ONELOGIN_never_issued']), 'unknown_request');
    }

    public function test_request_id_issued_for_another_connection_is_not_accepted(): void
    {
        $a = $this->staffConnection(['default_role' => 'Technician']);
        $b = $this->staffConnection(['default_role' => 'Technician'], ['fabrikam.com']);
        $idForA = $this->startLogin($a);

        $this->assertDenied($this->ssoLogin($b, ['inResponseTo' => $idForA]), 'unknown_request');
    }

    public function test_idp_initiated_response_without_in_response_to_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $xml = base64_decode(SamlFixture::response($c, $this->idpKey, []));
        $xml = preg_replace('/ InResponseTo="[^"]*"/', '', $xml);
        $xml = SamlFixture::signAssertion(preg_replace('/<ds:Signature.*<\/ds:Signature>/s', '', $xml), $this->idpKey);

        $this->assertDenied($this->post($c->acsUrl(), ['SAMLResponse' => base64_encode($xml)]), 'no_in_response_to');
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['audience' => 'https://evil.example/app']), 'invalid_response');
    }

    public function test_wrong_destination_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['destination' => 'https://evil.example/saml/acs']), 'invalid_response');
    }

    public function test_wrong_recipient_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['recipient' => 'https://evil.example/saml/acs']), 'invalid_response');
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['issuer' => 'https://sts.windows.net/99999999-9999-9999-9999-999999999999/']), 'invalid_response');
    }

    public function test_tenant_id_claim_must_match_connection(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $this->assertDenied($this->ssoLogin($c, ['tenantId' => '99999999-9999-9999-9999-999999999999']), 'tenant_mismatch');
    }

    public function test_assertion_for_connection_a_cannot_be_posted_to_connection_b(): void
    {
        $a = $this->staffConnection(['default_role' => 'Technician']);
        $b = $this->staffConnection(['default_role' => 'Technician'], ['fabrikam.com']);
        $idForB = $this->startLogin($b);
        // Valid, signed, same key, but addressed to A's endpoints.
        $b64 = SamlFixture::response($a, $this->idpKey, ['inResponseTo' => $idForB]);

        $this->assertDenied($this->post($b->acsUrl(), ['SAMLResponse' => $b64]));
    }

    public function test_groups_overage_fails_closed_with_clear_message(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);

        $response = $this->ssoLogin($c, ['extraAttributes' => ['http://schemas.microsoft.com/claims/groups.link' => ['https://graph.microsoft.com/v1.0/users/x/getMemberObjects']]]);

        $this->assertDenied($response, 'groups_overage');
        $this->assertStringContainsString('Groups assigned to the application', session('errors')->first('email'));
    }

    public function test_disabled_connection_does_not_accept_logins(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician', 'enabled' => false]);

        $this->get($c->loginUrl())->assertNotFound();
        $this->post($c->acsUrl(), ['SAMLResponse' => 'x'])->assertNotFound();
    }

    public function test_only_the_acs_route_is_csrf_exempt(): void
    {
        // Laravel skips CSRF while unit testing, so assert the route configuration itself.
        $csrf = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;
        $routes = app('router')->getRoutes();

        $this->assertContains($csrf, $routes->getByName('saml.acs')->excludedMiddleware());
        foreach (['saml.login', 'saml.metadata', 'sso.discover', 'admin.sso.store', 'admin.sso.settings'] as $name) {
            $this->assertNotContains($csrf, $routes->getByName($name)->excludedMiddleware(), $name);
        }
    }

    public function test_sp_metadata_is_published_per_connection(): void
    {
        $c = $this->staffConnection();

        $res = $this->get('/saml/'.$c->uuid.'/metadata')->assertOk();
        $this->assertStringContainsString($c->acsUrl(), $res->getContent());
        $this->assertStringContainsString('entityID="'.$c->spEntityId().'"', $res->getContent());
        $this->assertStringContainsString('WantAssertionsSigned="true"', $res->getContent());
    }
}
