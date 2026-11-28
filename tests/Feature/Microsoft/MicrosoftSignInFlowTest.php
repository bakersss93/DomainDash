<?php

namespace Tests\Feature\Microsoft;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class MicrosoftSignInFlowTest extends MicrosoftTestCase
{
    public function test_redirect_builds_a_pkce_authorize_request_against_the_organizations_endpoint(): void
    {
        $flow = $this->begin('amy@acme.com');

        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?', $flow['url']);
        $this->assertSame(self::APP_ID, $flow['client_id']);
        $this->assertSame('code', $flow['response_type']);
        $this->assertSame('openid profile email', $flow['scope']);
        $this->assertSame('https://dash.example.test/auth/microsoft/callback', $flow['redirect_uri']);
        $this->assertSame('S256', $flow['code_challenge_method']);
        $this->assertSame('amy@acme.com', $flow['login_hint']);
        $this->assertGreaterThanOrEqual(32, strlen($flow['state']));
        $this->assertGreaterThanOrEqual(32, strlen($flow['nonce']));
        $this->assertNotSame($flow['state'], $flow['nonce']);
        $this->assertStringNotContainsString(self::SECRET, $flow['url']);
        $this->assertNotNull(session('microsoft_oidc.verifier'), 'verifier stays server-side');
        $this->assertStringNotContainsString(session('microsoft_oidc.verifier'), $flow['url']);
    }

    public function test_happy_path_links_the_preexisting_client_user_and_logs_in(): void
    {
        $user = $this->customer('amy@acme.com');

        $flow = $this->begin();
        $this->fakeMicrosoft($this->idToken($this->claims($flow, ['preferred_username' => 'Amy@Acme.com'])));
        $this->oidcCallback($flow)->assertRedirect(config('fortify.home', '/dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        $user->refresh();
        $this->assertSame(self::OID, $user->microsoft_oid);
        $this->assertSame(self::TID, $user->microsoft_tid);
        $this->assertSame('microsoft', session('auth.via'));
        $this->assertTrue(AuditLog::where('action', 'auth.microsoft.login.success')->exists());
        $this->assertTrue(AuditLog::where('action', 'auth.microsoft.linked')->exists());

        // The token request carried the PKCE verifier matching the challenge, the secret and the exact redirect URI.
        Http::assertSent(function ($request) use ($flow) {
            if (! str_contains($request->url(), '/organizations/oauth2/v2.0/token')) {
                return false;
            }
            $d = $request->data();
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $d['code_verifier'], true)), '+/', '-_'), '=');

            return $d['grant_type'] === 'authorization_code' && $d['code'] === 'auth-code-1'
                && $challenge === $flow['code_challenge']
                && $d['client_secret'] === self::SECRET && $d['redirect_uri'] === 'https://dash.example.test/auth/microsoft/callback';
        });
    }

    public function test_it_never_creates_users(): void
    {
        $this->signIn(['preferred_username' => 'stranger@acme.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertContains('unknown_user', $this->failedReasons());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function test_invalid_id_tokens_are_refused(string $case, string $reason): void
    {
        $user = $this->customer();
        $flow = $this->begin();
        $other = $this->makeKey();

        $token = match ($case) {
            'bad_signature' => $this->idToken($this->claims($flow), $other),                       // right kid, wrong key
            'wrong_audience' => $this->idToken($this->claims($flow, ['aud' => 'ffffffff-0000-0000-0000-000000000000'])),
            'wrong_issuer' => $this->idToken($this->claims($flow, ['iss' => 'https://login.microsoftonline.com/'.self::OTHER_TID.'/v2.0'])),
            'issuer_v1' => $this->idToken($this->claims($flow, ['iss' => 'https://sts.windows.net/'.self::TID.'/'])),
            'issuer_other_host' => $this->idToken($this->claims($flow, ['iss' => 'https://evil.example/'.self::TID.'/v2.0'])),
            'tid_claim_differs_from_issuer' => $this->idToken($this->claims($flow, ['tid' => self::OTHER_TID, 'iss' => 'https://login.microsoftonline.com/'.self::TID.'/v2.0'])),
            'expired' => $this->idToken($this->claims($flow, ['exp' => time() - 600, 'iat' => time() - 4000, 'nbf' => time() - 4000])),
            'not_yet_valid' => $this->idToken($this->claims($flow, ['nbf' => time() + 3600, 'iat' => time() + 3600, 'exp' => time() + 7200])),
            'no_exp' => $this->idToken($this->claims($flow, ['exp' => null])),
            'nonce_mismatch' => $this->idToken($this->claims($flow, ['nonce' => 'not-the-nonce'])),
            'nonce_missing' => $this->idToken($this->claims($flow, ['nonce' => null])),
            'tid_missing' => $this->idToken($this->claims($flow, ['tid' => null])),
            'oid_missing' => $this->idToken($this->claims($flow, ['oid' => null])),
            'alg_hs256' => JWT::encode($this->claims($flow), 'shared-secret-shared-secret-shared-secret', 'HS256', self::KID),
            'unknown_kid' => $this->idToken($this->claims($flow), null, 'rotated-away'),
            'malformed' => 'not.a.jwt.at.all',
        };

        $this->fakeMicrosoft($token);
        $this->oidcCallback($flow)->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->microsoft_oid, 'nothing is linked on a refused token');
        $this->assertContains($reason, $this->failedReasons());
    }

    public static function badTokens(): array
    {
        return [
            'bad signature' => ['bad_signature', 'bad_signature'],
            'wrong audience' => ['wrong_audience', 'bad_audience'],
            'wrong issuer tenant' => ['wrong_issuer', 'bad_issuer'],
            'v1 issuer' => ['issuer_v1', 'bad_issuer'],
            'other issuer host' => ['issuer_other_host', 'bad_issuer'],
            'tid differs from iss' => ['tid_claim_differs_from_issuer', 'bad_issuer'],
            'expired' => ['expired', 'token_expired'],
            'not yet valid' => ['not_yet_valid', 'token_not_yet_valid'],
            'no exp' => ['no_exp', 'exp_missing'],
            'nonce mismatch' => ['nonce_mismatch', 'nonce_mismatch'],
            'nonce missing' => ['nonce_missing', 'nonce_mismatch'],
            'tid missing' => ['tid_missing', 'tid_missing'],
            'oid missing' => ['oid_missing', 'oid_missing'],
            'HS256 algorithm confusion' => ['alg_hs256', 'bad_alg'],
            'unknown kid' => ['unknown_kid', 'token_invalid'],
            'malformed' => ['malformed', 'token_malformed'],
        ];
    }

    public function test_small_clock_skew_is_tolerated_but_not_more(): void
    {
        $this->customer();
        $flow = $this->begin();
        $this->fakeMicrosoft($this->idToken($this->claims($flow, ['exp' => time() - 30, 'iat' => time() - 3000, 'nbf' => time() - 3000])));
        $this->oidcCallback($flow)->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertAuthenticated();
    }

    public function test_state_mismatch_missing_state_and_missing_flow_are_refused(): void
    {
        $this->customer();

        $flow = $this->begin();
        $this->fakeMicrosoft($this->idToken($this->claims($flow)));
        $this->oidcCallback($flow, ['state' => 'forged'])->assertRedirect(route('login'));
        $this->assertContains('state_mismatch', $this->failedReasons());
        $this->assertGuest();

        $flow = $this->begin();
        $this->get(route('microsoft.callback', ['code' => 'x']))->assertRedirect(route('login'));
        $this->assertGuest();

        // No flow in this session at all (callback opened in a different browser / login CSRF).
        $this->flushSession();
        $this->oidcCallback($flow)->assertRedirect(route('login'));
        $this->assertContains('no_flow', $this->failedReasons());
        $this->assertGuest();
    }

    public function test_state_is_single_use_so_a_callback_cannot_be_replayed(): void
    {
        $this->customer();
        $flow = $this->begin();
        $this->fakeMicrosoft($this->idToken($this->claims($flow)));
        $this->oidcCallback($flow)->assertRedirect(config('fortify.home', '/dashboard'));
        $this->post('/logout');
        $this->assertGuest();

        $this->oidcCallback($flow)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('no_flow', $this->failedReasons());
    }

    public function test_expired_flow_is_refused(): void
    {
        $this->customer();
        $flow = $this->begin();
        session(['microsoft_oidc.created_at' => time() - 3600]);
        $this->fakeMicrosoft($this->idToken($this->claims($flow)));

        $this->oidcCallback($flow)->assertRedirect(route('login'));
        $this->assertContains('flow_expired', $this->failedReasons());
    }

    public function test_microsoft_refusing_the_code_exchange_denies_login(): void
    {
        $this->customer();
        $flow = $this->begin();
        $this->fakeMicrosoft('', null, 400, ['error' => 'invalid_grant', 'error_description' => 'AADSTS70008 PKCE verification failed']);

        $this->oidcCallback($flow)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('token_rejected', $this->failedReasons());
        $this->assertStringNotContainsString('AADSTS', json_encode(AuditLog::where('action', 'auth.microsoft.login.failed')->first()->context));
    }

    public function test_error_response_from_microsoft_is_audited_and_denied(): void
    {
        $this->customer();
        $flow = $this->begin();

        $this->get(route('microsoft.callback', ['error' => 'access_denied', 'error_description' => '<script>', 'state' => $flow['state']]))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('idp_error', $this->failedReasons());
    }

    public function test_jwks_are_cached_per_tenant(): void
    {
        $this->customer();
        $flow = $this->begin();
        $this->fakeMicrosoft($this->idToken($this->claims($flow)));
        $this->oidcCallback($flow)->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertNotNull(Cache::get('microsoft_jwks:'.self::TID));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/'.self::TID.'/discovery/v2.0/keys'));
    }

    // ---- matching ---------------------------------------------------------------------------------------------

    public function test_noauth_the_email_claim_is_never_used_for_matching(): void
    {
        $victim = $this->customer('ceo@acme.com');

        // Attacker's own tenant: UPN is theirs, but they set the (mutable) email claim to the victim's address.
        $this->signIn(['tid' => self::OTHER_TID, 'preferred_username' => 'mallory@evil.example', 'email' => 'ceo@acme.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($victim->fresh()->microsoft_oid);
        $this->assertContains('unknown_user', $this->failedReasons());
    }

    public function test_email_claim_is_only_honoured_when_the_tenant_verified_the_domain_owner(): void
    {
        $victim = $this->customer('ceo@acme.com');

        $this->signIn(['preferred_username' => 'x@evil.example', 'email' => 'ceo@acme.com', 'xms_edov' => false])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->signIn(['preferred_username' => 'x@evil.example', 'email' => 'ceo@acme.com', 'xms_edov' => 'false'])->assertRedirect(route('login'));
        $this->assertGuest();

        $this->signIn(['preferred_username' => 'x@evil.example', 'email' => 'ceo@acme.com', 'xms_edov' => true]);
        $this->assertAuthenticatedAs($victim->fresh());
    }

    public function test_after_linking_the_user_is_matched_on_tenant_and_object_id_only(): void
    {
        $user = $this->customer('amy@acme.com');
        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->post('/logout');
        $this->assertGuest();

        // Email in DomainDash changes: same (tid, oid) still gets in, whatever the UPN says.
        $user->forceFill(['email' => 'amy.new@acme.com'])->save();
        $this->signIn(['preferred_username' => 'someone.else@acme.com'])->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_linked_user_cannot_be_reached_from_another_object_id_or_tenant(): void
    {
        $user = $this->customer('amy@acme.com');
        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->post('/logout');

        // Same UPN, different object id (e.g. a new account re-using the name).
        $this->signIn(['oid' => 'dddddddd-1111-2222-3333-444444444444'])->assertRedirect(route('login'));
        $this->assertGuest();
        // Same object id in a different tenant.
        $this->signIn(['tid' => self::OTHER_TID])->assertRedirect(route('login'));
        $this->assertGuest();

        $user->refresh();
        $this->assertSame(self::OID, $user->microsoft_oid);
        $this->assertSame(self::TID, $user->microsoft_tid);
        $this->assertSame(['link_mismatch', 'link_mismatch'], array_values(array_filter($this->failedReasons())));
    }

    public function test_one_microsoft_identity_cannot_be_linked_to_two_users(): void
    {
        $a = $this->customer('amy@acme.com');
        $b = $this->customer('bob@acme.com', Client::first());
        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->post('/logout');

        // Bob's UPN with Amy's (tid, oid): matched to Amy by (tid, oid), never to Bob.
        $this->signIn(['preferred_username' => 'bob@acme.com']);
        $this->assertAuthenticatedAs($a->fresh());
        $this->assertNull($b->fresh()->microsoft_oid);
    }

    public function test_staff_users_cannot_use_client_sign_in(): void
    {
        $tech = User::factory()->create(['sso_enabled' => true, 'email' => 'tina@acme.com']);
        $tech->assignRole('Technician');
        $none = User::factory()->create(['sso_enabled' => true, 'email' => 'norole@acme.com']); // no role = treated as staff (fail closed)

        $this->signIn(['preferred_username' => 'tina@acme.com'])->assertRedirect(route('login'));
        $this->signIn(['preferred_username' => 'norole@acme.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($tech->fresh()->microsoft_oid);
        $this->assertNull($none->fresh()->microsoft_oid);
        $this->assertSame(['staff_user', 'staff_user'], $this->failedReasons());
    }

    public function test_staff_with_an_existing_link_is_still_refused(): void
    {
        $tech = User::factory()->create(['sso_enabled' => true, 'email' => 'tina@acme.com', 'microsoft_oid' => self::OID, 'microsoft_tid' => self::TID]);
        $tech->assignRole('Technician');

        $this->signIn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('staff_user', $this->failedReasons());
    }

    public function test_inactive_and_unassigned_users_are_refused(): void
    {
        $this->customer('off@acme.com', null, ['is_active' => false]);
        $orphan = User::factory()->create(['sso_enabled' => true, 'email' => 'orphan@acme.com']);
        $orphan->assignRole('Customer');

        $this->signIn(['preferred_username' => 'off@acme.com'])->assertRedirect(route('login'));
        $this->signIn(['preferred_username' => 'orphan@acme.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(['inactive', 'no_client'], $this->failedReasons());
    }

    // ---- tenant restrictions -------------------------------------------------------------------------------

    public function test_global_allowed_tenant_list(): void
    {
        $this->configureMicrosoft(['allowed_tenants' => [self::OTHER_TID]]);
        $this->customer('amy@acme.com', Client::create(['business_name' => 'Other', 'microsoft_tenant_id' => self::OTHER_TID]));

        $this->signIn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('tenant_not_allowed', $this->failedReasons());

        $this->signIn(['tid' => self::OTHER_TID]);
        $this->assertAuthenticated();
    }

    public function test_client_tenant_lock_is_enforced_and_auto_filled_on_first_link(): void
    {
        $this->configureMicrosoft(['require_client_tenant' => false]);
        $locked = Client::create(['business_name' => 'Locked', 'microsoft_tenant_id' => self::OTHER_TID]);
        $this->customer('amy@acme.com', $locked);

        $this->signIn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('client_tenant_mismatch', $this->failedReasons());

        $open = Client::create(['business_name' => 'Open']);
        $this->customer('bob@acme.com', $open);
        $this->signIn(['preferred_username' => 'bob@acme.com', 'oid' => 'eeeeeeee-1111-2222-3333-444444444444']);
        $this->assertAuthenticated();
        $this->assertSame(self::TID, $open->fresh()->microsoft_tenant_id, 'tenant recorded on first link');
    }

    public function test_tenant_is_not_auto_filled_for_a_user_with_several_clients(): void
    {
        $this->configureMicrosoft(['require_client_tenant' => false]);
        $a = Client::create(['business_name' => 'A']);
        $b = Client::create(['business_name' => 'B']);
        $u = $this->customer('amy@acme.com', $a);
        $u->clients()->attach($b->id);

        $this->signIn();
        $this->assertAuthenticated();
        $this->assertNull($a->fresh()->microsoft_tenant_id);
        $this->assertNull($b->fresh()->microsoft_tenant_id);
    }

    public function test_feature_off_or_incomplete_refuses_everything(): void
    {
        $this->customer();
        $flow = $this->begin();
        $this->configureMicrosoft(['enabled' => false]);

        $this->get(route('microsoft.redirect'))->assertRedirect(route('login'));
        $this->fakeMicrosoft($this->idToken($this->claims($flow)));
        $this->oidcCallback($flow)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('disabled', $this->failedReasons());

        $this->configureMicrosoft(['client_secret' => '']);
        $this->get(route('microsoft.redirect'))->assertRedirect(route('login'));
    }




    public function test_existing_users_need_sso_enabled_and_the_refusal_is_audited(): void
    {
        $user = $this->customer('amy@acme.com', null, ['sso_enabled' => false]);

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->microsoft_oid, 'must not link');
        $this->assertContains('sso_not_enabled', $this->failedReasons());
        $this->assertTrue(AuditLog::where('action', 'auth.microsoft.login.failed')->exists());
    }

    public function test_keep_me_logged_in_carries_through_the_microsoft_round_trip(): void
    {
        $user = $this->customer();
        $this->withSession(['sso.remember' => true]);

        $res = $this->signIn();

        $this->assertAuthenticatedAs($user->fresh());
        $remembered = collect($res->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertTrue($remembered);
        $this->assertNull(session('sso.remember'));
    }
}
