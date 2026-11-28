<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MfaRecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        Role::findOrCreate('Customer');
        $u = User::factory()->create();
        $u->assignRole('Customer');

        return $u;
    }

    /** Fortify rejects a replayed code, so each call within a test uses the previous 30-second step (inside the verification window). */
    private int $step = 0;

    private function otp(string $secret): string
    {
        $g = new Google2FA();

        return $g->oathTotp($secret, $g->getTimestamp() - ($this->step++ % 2));
    }

    public function test_confirming_setup_returns_the_recovery_codes_once(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson(route('me.mfa.start'))->assertOk();
        $u->refresh();
        $secret = decrypt($u->two_factor_secret);
        $stored = json_decode(decrypt($u->two_factor_recovery_codes), true);
        $this->assertCount(8, $stored);

        $res = $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => $this->otp($secret)])->assertOk();
        $res->assertJsonPath('configured', true);
        $this->assertSame($stored, $res->json('recovery_codes'));

        // Neither the status nor start endpoint ever hands the codes out again.
        $this->actingAs($u)->getJson(route('me.mfa.status'))->assertJsonMissingPath('recovery_codes');
        $this->actingAs($u)->postJson(route('me.mfa.start'))->assertJsonMissingPath('recovery_codes');
    }

    public function test_invalid_confirmation_returns_no_codes(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson(route('me.mfa.start'))->assertOk();

        $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => '000000'])->assertStatus(422)->assertJsonMissingPath('recovery_codes');
    }

    public function test_regenerate_needs_the_current_totp_code_and_is_audited(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson(route('me.mfa.start'));
        $u->refresh();
        $secret = decrypt($u->two_factor_secret);
        $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => $this->otp($secret)])->assertOk();
        $old = json_decode(decrypt($u->fresh()->two_factor_recovery_codes), true);

        $this->actingAs($u)->postJson(route('me.account.mfa-recovery-codes'), [])->assertStatus(422);
        $this->actingAs($u)->postJson(route('me.account.mfa-recovery-codes'), ['mfa_code' => '000000'])->assertStatus(422);
        // A recovery code is not accepted as the proof.
        $this->actingAs($u)->postJson(route('me.account.mfa-recovery-codes'), ['mfa_code' => $old[0]])->assertStatus(422);
        $this->assertSame($old, json_decode(decrypt($u->fresh()->two_factor_recovery_codes), true));

        $res = $this->actingAs($u)->postJson(route('me.account.mfa-recovery-codes'), ['mfa_code' => $this->otp($secret)])->assertOk();
        $new = $res->json('recovery_codes');
        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertSame($new, json_decode(decrypt($u->fresh()->two_factor_recovery_codes), true));
        $this->assertTrue(AuditLog::where('action', 'auth.mfa_recovery_regenerated')->exists());
    }

    public function test_regenerate_requires_mfa_to_be_set_up(): void
    {
        $this->actingAs($this->user())->postJson(route('me.account.mfa-recovery-codes'), ['mfa_code' => '123456'])->assertStatus(422);
    }

    public function test_a_recovery_code_completes_the_two_factor_challenge(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson(route('me.mfa.start'));
        $u->refresh();
        $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => $this->otp(decrypt($u->two_factor_secret))]);
        $codes = json_decode(decrypt($u->fresh()->two_factor_recovery_codes), true);
        auth()->logout();
        session()->flush();

        $this->withSession(['login.id' => $u->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
            ->assertRedirect();
        $this->assertAuthenticatedAs($u);
        $this->assertNotContains($codes[0], json_decode(decrypt($u->fresh()->two_factor_recovery_codes), true));
    }

    public function test_primary_method_option_is_gone_from_the_mfa_settings_form(): void
    {
        Role::findOrCreate('Administrator');
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');

        $html = $this->actingAs($admin)->get(route('admin.settings', ['area' => 'mfa']))->getContent();
        $this->assertStringNotContainsString('Email one-time passcode', $html);
        $this->assertStringNotContainsString('mfa[default_method]', $html);
    }

    public function test_layout_contains_the_recovery_codes_modal_with_warning(): void
    {
        $html = $this->actingAs($this->user())->get('/dashboard')->getContent();
        $this->assertStringContainsString('Save your recovery codes', $html);
        $this->assertStringContainsString('Regenerate recovery codes', $html);
    }
}
