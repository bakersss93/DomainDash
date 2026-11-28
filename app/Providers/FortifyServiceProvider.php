<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where('email', $request->string('email')->toString())->first();

            if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'This user is disabled. Please contact the system administrator.',
                ]);
            }

            // Login-method policy (staff / clients / per client / per-user exception). The answer is the same as for a
            // wrong password, so the form never confirms that the password was right; the attempt is audited.
            if (! \App\Services\Auth\LoginPolicy::passwordAllowed($user)) {
                \App\Services\AuditLogger::logSystem('auth.password.blocked_by_policy', "Password sign-in refused by login-method policy for {$user->email}", [
                    'function' => 'sso', 'automated' => false, 'user_id' => $user->id, 'mode' => \App\Services\Auth\LoginPolicy::modeFor($user),
                ], ['user_email' => $user->email]);

                return null;
            }

            return $user;
        });

        // Same response whether or not the address exists (no account enumeration).
        $this->app->singleton(\Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse::class, \App\Http\Responses\GenericPasswordResetLinkResponse::class);

        // Fortify registers the confirm-password POST without any throttle.
        $this->app->booted(function () {
            $routes = \Illuminate\Support\Facades\Route::getRoutes();
            $routes->refreshNameLookups();
            $routes->getByName('password.confirm.store')?->middleware('throttle:6,1');
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Email-first step 1 and the SSO action: 10 per minute per IP + email. Applies to every address alike.
        RateLimiter::for('login-identify', function (Request $request) {
            return Limit::perMinute(10)->by(Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        });

        // Lookup used by the login page while the email is typed: 20 per minute per IP.
        RateLimiter::for('login-method', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        Fortify::loginView(function (Request $request) {
            // old('email') wins: it is what a failed password attempt typed. The session value comes from step 1.
            $email = strtolower(trim((string) ($request->old('email') ?: $request->session()->get('login.email', ''))));
            $valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

            $step = $valid ? \App\Services\Auth\LoginPolicy::stepFor($email) : null;
            $state = 'email';
            if ($step) {
                $state = match (true) {
                    $step['mode'] === 'password' => 'password',
                    $step['password'] && $request->session()->get('login.pw') => 'password-sso',
                    $step['password'] => 'sso-both',
                    default => 'sso',
                };
            }
            return view('auth.login', [
                'email' => $valid ? $email : '',
                'state' => $state,
                'brand' => \App\Support\Branding::companyName(),
            ]);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
