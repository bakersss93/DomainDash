<?php

namespace Tests\Feature\Microsoft;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Microsoft\MicrosoftSettings;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Locally generated RSA keys plus faked Microsoft token / JWKS endpoints (no network). */
abstract class MicrosoftTestCase extends TestCase
{
    use RefreshDatabase;

    protected const APP_ID = '11111111-aaaa-bbbb-cccc-222222222222';
    protected const SECRET = 'super-secret-value-123';
    protected const TID = 'aaaaaaaa-1111-2222-3333-444444444444';
    protected const OTHER_TID = 'bbbbbbbb-1111-2222-3333-444444444444';
    protected const OID = 'cccccccc-1111-2222-3333-444444444444';
    protected const KID = 'test-kid-1';

    protected array $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://dash.example.test']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['settings.manage', 'users.manage', 'domains.view', 'tickets.create'] as $p) {
            Permission::findOrCreate($p);
        }
        Role::findOrCreate('Administrator')->syncPermissions(['settings.manage', 'users.manage', 'domains.view']);
        Role::findOrCreate('Technician')->syncPermissions(['domains.view']);
        Role::findOrCreate('Customer')->syncPermissions(['domains.view', 'tickets.create']);

        $this->key = $this->makeKey();
        $this->configureMicrosoft();
    }

    protected function configureMicrosoft(array $over = []): void
    {
        Setting::put(MicrosoftSettings::KEY, $over + [
            'enabled' => true, 'client_id' => self::APP_ID, 'client_secret' => self::SECRET,
            'allowed_tenants' => [], 'trust_mfa_clients' => true,
        ]);
    }

    protected function makeKey(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $d = openssl_pkey_get_details($res);
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return ['pem' => $pem, 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])];
    }

    protected function jwks(?array $key = null, string $kid = self::KID): array
    {
        $key ??= $this->key;

        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'n' => $key['n'], 'e' => $key['e']]]];
    }

    protected function customer(string $email = 'amy@acme.com', ?Client $client = null, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['email' => $email, 'sso_enabled' => true]);
        $u->assignRole('Customer');
        $u->clients()->attach(($client ?? Client::create(['business_name' => 'Acme', 'microsoft_tenant_id' => self::TID]))->id);

        return $u;
    }

    /** Start the flow and return state / nonce / challenge parsed from the authorize URL. */
    protected function begin(?string $hint = null): array
    {
        $res = $this->get(route('microsoft.redirect', $hint ? ['login_hint' => $hint] : []));
        $res->assertRedirect();
        $url = $res->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return ['url' => $url] + $q;
    }

    protected function claims(array $flow, array $over = []): array
    {
        $tid = $over['tid'] ?? self::TID;

        return array_filter($over + [
            'iss' => 'https://login.microsoftonline.com/'.$tid.'/v2.0',
            'aud' => self::APP_ID,
            'tid' => $tid,
            'oid' => self::OID,
            'sub' => 'sub-123',
            'ver' => '2.0',
            'nonce' => $flow['nonce'],
            'preferred_username' => 'amy@acme.com',
            'name' => 'Amy Adams',
            'iat' => time() - 10,
            'nbf' => time() - 10,
            'exp' => time() + 3600,
        ], fn ($v) => $v !== null);
    }

    protected function idToken(array $claims, ?array $key = null, string $kid = self::KID): string
    {
        return JWT::encode($claims, ($key ?? $this->key)['pem'], 'RS256', $kid);
    }

    /** @param array|null $token token endpoint body (default: success with the given id_token) */
    protected function fakeMicrosoft(string $idToken, ?array $jwks = null, int $tokenStatus = 200, ?array $tokenBody = null)
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response($tokenBody ?? ['id_token' => $idToken, 'access_token' => 'x', 'token_type' => 'Bearer'], $tokenStatus),
            'login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response($jwks ?? $this->jwks()),
        ]);
    }

    protected function oidcCallback(array $flow, array $over = [])
    {
        return $this->get(route('microsoft.callback', $over + ['code' => 'auth-code-1', 'state' => $flow['state']]));
    }

    /** Full happy-path attempt with the given claim overrides. */
    protected function signIn(array $claimOver = [], ?string $hint = null, ?array $key = null, ?array $jwks = null)
    {
        $flow = $this->begin($hint);
        $this->fakeMicrosoft($this->idToken($this->claims($flow, $claimOver), $key), $jwks);

        return $this->oidcCallback($flow);
    }

    protected function failedReasons(): array
    {
        return \App\Models\AuditLog::where('action', 'auth.microsoft.login.failed')->get()->map(fn ($l) => $l->context['reason'] ?? null)->all();
    }

    /** Forget the guard's cached user so the next request re-reads the (changed) row, like a real new request. */
    protected function nextRequest()
    {
        $this->app['auth']->forgetGuards();
    }
}
