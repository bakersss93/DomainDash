<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
}
