<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\RecoveryCode;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

class UserSettingsController extends Controller
{
    public function toggleDark(Request $request)
    {
        $user = $request->user();
        $user->dark_mode = ! (bool) $user->dark_mode;
        $user->save();

        return back();
    }

    public function accountDetails(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'mfa_configured' => $user->hasConfiguredMfa(),
        ]);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ])->validate();

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
        ])->save();

        return response()->json(['updated' => true]);
    }

    public function changePassword(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $user = $request->user();

        $data = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'mfa_code' => ['nullable', 'string'],
        ])->validate();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        if ($user->hasConfiguredMfa()) {
            if (empty($data['mfa_code'])) {
                return response()->json(['message' => 'Enter your MFA code to change password.'], 422);
            }

            $this->assertValidMfaCode($user, $data['mfa_code'], $provider);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        return response()->json(['updated' => true]);
    }

    public function reEnrollMfa(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $user = $request->user();

        if ($user->mfa_preference === 'disabled') {
            return response()->json(['message' => 'MFA is disabled for this account.'], 422);
        }

        $data = Validator::make($request->all(), [
            'mfa_code' => ['nullable', 'string'],
        ])->validate();

        if ($user->hasConfiguredMfa()) {
            if (empty($data['mfa_code'])) {
                return response()->json(['message' => 'Enter your current MFA code to re-enroll MFA.'], 422);
            }

            $this->assertValidMfaCode($user, $data['mfa_code'], $provider);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'mfa_prompted_at' => null,
        ])->save();

        $request->session()->put('mfa.setup', [
            'show' => true,
            'required' => $user->mfa_preference === 'enforced',
        ]);

        return response()->json(['reenroll' => true]);
    }

    public function mfaSetupStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasConfiguredMfa()) {
            return response()->json([
                'configured' => true,
            ]);
        }

        if (empty($user->two_factor_secret)) {
            return response()->json([
                'configured' => false,
                'ready' => false,
            ]);
        }

        return response()->json([
            'configured' => false,
            'ready' => true,
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            'setup_key' => decrypt($user->two_factor_secret),
        ]);
    }

    public function startMfaSetup(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $user = $request->user();

        if ($user->mfa_preference === 'disabled') {
            return response()->json(['message' => 'MFA is disabled for this account.'], 422);
        }

        if ($user->hasConfiguredMfa()) {
            return response()->json([
                'configured' => true,
            ]);
        }

        $secret = $provider->generateSecretKey();
        $recoveryCodes = Collection::times(8, fn () => RecoveryCode::generate())->all();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'configured' => false,
            'ready' => true,
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            'setup_key' => decrypt($user->two_factor_secret),
        ]);
    }

    public function confirmMfaSetup(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (empty($user->two_factor_secret)) {
            return response()->json(['message' => 'Start MFA setup before confirming the code.'], 422);
        }

        $valid = $provider->verify(decrypt($user->two_factor_secret), $data['code']);

        if (! $valid) {
            return response()->json(['message' => 'The code is invalid. Please try again.'], 422);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'mfa_prompted_at' => now(),
        ])->save();

        $request->session()->forget('mfa.setup');

        return response()->json([
            'configured' => true,
        ]);
    }

    public function dismissMfaPrompt(Request $request)
    {
        $user = $request->user();

        if ($user->mfa_preference !== 'enabled') {
            return response()->json(['message' => 'This prompt cannot be dismissed.'], 422);
        }

        $user->forceFill([
            'mfa_prompted_at' => now(),
        ])->save();

        $request->session()->forget('mfa.setup');

        return response()->json(['dismissed' => true]);
    }

    private function assertValidMfaCode($user, string $code, TwoFactorAuthenticationProvider $provider): void
    {
        $valid = $provider->verify(decrypt($user->two_factor_secret), $code);

        if (! $valid) {
            throw new \Illuminate\Validation\ValidationException(
                Validator::make([], []),
                response()->json(['message' => 'The MFA code is invalid.'], 422)
            );
        }
    }
}
