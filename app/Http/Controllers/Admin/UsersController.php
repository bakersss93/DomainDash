<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UsersController extends Controller
{
    /**
     * List users with role filter.
     */
    public function index(Request $request)
    {
        $q = User::query()->with('roles', 'clients');

        if ($role = $request->get('role')) {
            // Spatie\Permission: scope to users with the given role
            $q->role($role);
        }

        $users = $q->orderBy('name')->paginate(25);
        $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get();

        return view('admin.users.index', compact('users', 'roles'));
    }

    /**
     * Show create user form.
     */
    public function create(Request $request)
    {
        $clients = Client::orderBy('business_name')->get();
        $roles = $this->assignableRoles($request);

        return view('admin.users.create', compact('clients', 'roles'));
    }

    /**
     * Store new user.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'   => 'required|string|max:255',
            'last_name'    => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'role'         => ['required', 'string', $this->roleRule()],
            'client_ids'   => 'array',
            'client_ids.*' => 'integer|exists:clients,id',
            'password'     => ['nullable', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'confirmed'],
            'mfa_preference' => 'required|in:disabled,enabled,enforced',
            'is_active' => 'nullable|boolean',
        ]);
        $this->assertMayAssign($request, $data['role']);
        $data['mfa_preference'] = $this->effectiveMfaPreference($data['role'], $data['mfa_preference']);

        $password = ($data['password'] ?? null) ?: Str::random(12);

        $user = User::create([
            'name'     => trim($data['first_name'].' '.$data['last_name']),
            'email'    => $data['email'],
            'password' => Hash::make($password),
            'mfa_preference' => $data['mfa_preference'],
            'is_active' => $request->boolean('is_active', true),
        ]);
        // Admin-created accounts are trusted; MustVerifyEmail must not lock them out.
        $user->forceFill([
            'email_verified_at' => now(),
            // Microsoft sign-in is an administrator-only switch (see update()).
            'sso_enabled' => (bool) $request->user()?->hasRole('Administrator') && $request->boolean('sso_enabled'),
        ])->save();

        // assign role (Spatie)
        $user->syncRoles([$data['role']]);

        // link to clients (many-to-many)
        if (!empty($data['client_ids'])) {
            $user->clients()->sync($data['client_ids']);
        }

        // TODO: send welcome / password email if desired

        return redirect()
            ->route('admin.users')
            ->with('status', 'User created successfully.');
    }

    /**
     * Show edit user form.
     */
    public function edit(Request $request, User $user)
    {
        $roles = $this->assignableRoles($request, $user);
        $clients = Client::orderBy('business_name')->get();
        $currentRole      = optional($user->roles->first())->name;
        $currentClientIds = $user->clients->pluck('id')->all();

        $parts = explode(' ', $user->name ?? '', 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';

        return view('admin.users.edit', compact(
            'user',
            'clients',
            'currentRole',
            'currentClientIds',
            'first',
            'last',
            'roles'
        ));
    }

    /**
     * Update user details.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'first_name'   => 'required|string|max:255',
            'last_name'    => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email,'.$user->id,
            'role'         => ['required', 'string', $this->roleRule()],
            'client_ids'   => 'array',
            'client_ids.*' => 'integer|exists:clients,id',
            'mfa_preference' => 'required|in:disabled,enabled,enforced',
            'is_active' => 'nullable|boolean',
        ]);
        $this->assertMayAssign($request, $data['role'], $user);
        $data['mfa_preference'] = $this->effectiveMfaPreference($data['role'], $data['mfa_preference']);

        $previousRole = optional($user->roles->first())->name;
        $wasActive = (bool) $user->is_active;
        $wasExempt = (bool) $user->password_login_allowed;

        // The password exception is an administrator-only control; for anyone else the field is ignored.
        $isAdmin = (bool) $request->user()?->hasRole('Administrator');
        $exempt = $isAdmin ? $request->boolean('password_login_allowed') : $wasExempt;
        // Same for the per-user "Enable Microsoft sign-in" switch.
        $wasSso = (bool) $user->sso_enabled;
        $sso = $isAdmin ? $request->boolean('sso_enabled') : $wasSso;

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $data, $request, $exempt, $sso) {
            $user->update([
                'name'  => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'mfa_preference' => $data['mfa_preference'],
                'is_active' => $request->boolean('is_active', true),
            ]);
            $user->forceFill(['password_login_allowed' => $exempt, 'sso_enabled' => $sso])->save();

            $user->syncRoles([$data['role']]);
            $user->clients()->sync($data['client_ids'] ?? []);

            $this->assertStillRecoverable();
        });

        if ($isAdmin && $wasExempt !== $exempt) {
            \App\Services\AuditLogger::logAction('user.password_exception.changed', $user, ($exempt ? 'Granted' : 'Removed')." the password sign-in exception for {$user->email}", [
                'old_values' => ['password_login_allowed' => $wasExempt], 'new_values' => ['password_login_allowed' => $exempt],
            ]);
        }

        if ($isAdmin && $wasSso !== $sso) {
            \App\Services\AuditLogger::logAction('user.sso_enabled.changed', $user, ($sso ? 'Enabled' : 'Disabled')." Microsoft sign-in for {$user->email}", [
                'old_values' => ['sso_enabled' => $wasSso], 'new_values' => ['sso_enabled' => $sso],
            ]);
        }

        if ($previousRole !== $data['role'] || ($wasActive && ! $user->is_active) || ($wasSso && ! $sso)) {
            \App\Support\SessionRevoker::revoke($user, $request->user()?->is($user) ? $request->session()->getId() : null);
        }

        return redirect()
            ->route('admin.users')
            ->with('status', 'User updated successfully.');
    }

    /** Every role from Settings > Permissions (custom roles included); only Administrators may hand out Administrator. */
    private function assignableRoles(Request $request, ?User $editing = null)
    {
        $isAdmin = (bool) $request->user()?->hasRole('Administrator');

        return Role::query()->where('guard_name', 'web')->orderBy('name')->get()
            ->when(! $isAdmin, fn ($roles) => $roles->reject(fn ($r) => $r->name === 'Administrator'
                && ! ($editing && $editing->hasRole('Administrator'))))
            ->values();
    }

    private function roleRule()
    {
        return Rule::exists('roles', 'name')->where('guard_name', 'web');
    }

    /** Only Administrators may assign the Administrator role, or change a user who holds it. */
    private function assertMayAssign(Request $request, string $role, ?User $target = null): void
    {
        if ($request->user()?->hasRole('Administrator')) {
            return;
        }
        if ($role === 'Administrator' || $target?->hasRole('Administrator')) {
            throw ValidationException::withMessages(['role' => 'Only an Administrator can assign the Administrator role or change an Administrator.']);
        }
    }

    /** While staff password sign-in is closed, an active Administrator with the password exception must always remain. */
    private function assertStillRecoverable(): void
    {
        if (\App\Services\Auth\LoginPolicy::staffMode() === \App\Services\Auth\LoginPolicy::MICROSOFT_ONLY
            && ! User::role('Administrator')->where('is_active', true)->where('password_login_allowed', true)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password_login_allowed' => 'Password sign-in is closed for staff, so at least one active Administrator must keep the "may also use password" exception.',
            ]);
        }
    }

    /** Administrator removes a client user's Microsoft link (they re-link on their next Microsoft sign-in). */
    public function unlinkMicrosoft(Request $request, User $user)
    {
        abort_unless($request->user()?->hasRole('Administrator'), 403);

        if ($user->microsoft_oid === null) {
            return back()->with('status', 'This user has no linked Microsoft account.');
        }

        $old = ['microsoft_tid' => $user->microsoft_tid, 'microsoft_oid' => $user->microsoft_oid];
        $user->forceFill(['microsoft_oid' => null, 'microsoft_tid' => null, 'microsoft_linked_at' => null])->save();
        \App\Support\SessionRevoker::revoke($user);

        \App\Services\AuditLogger::logAction('auth.microsoft.unlinked', $user, "Microsoft account unlinked for {$user->email}", ['old_values' => $old]);

        return back()->with('status', 'Microsoft account unlinked.');
    }

    /**
     * Show combined password screen (set + send reset link).
     */
    public function editPassword(User $user)
    {
        return view('admin.users.password', compact('user'));
    }

    /**
     * Set password manually.
     */
    public function updatePassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        \App\Support\SessionRevoker::revoke($user, $request->user()?->is($user) ? $request->session()->getId() : null);

        return redirect()
            ->route('admin.users')
            ->with('status', 'Password updated for '.$user->email);
    }

    /**
     * Send standard Laravel reset-link email.
     */
    public function sendPasswordLink(User $user)
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        return back()->with('status',
            $status === Password::RESET_LINK_SENT
                ? 'Password reset link sent to '.$user->email
                : 'Unable to send reset link (check mail settings).'
        );
    }

    /**
     * Reset MFA (2FA) so user must re-enrol.
     */
    public function resetMfa(User $user)
    {
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'mfa_prompted_at'           => null,
        ])->save();

        return back()->with('status', 'MFA reset for '.$user->email.'. They will need to re-enrol.');
    }



    /**
     * Delete a user account.
     */
    public function destroy(Request $request, User $user)
    {
        if ($request->user() && $request->user()->is($user)) {
            return back()->withErrors(['user' => 'You cannot delete your own account while logged in.']);
        }

        $email = $user->email;
        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            $user->clients()->detach();
            $user->syncRoles([]);
            $user->delete();

            $this->assertStillRecoverable();
        });

        return redirect()
            ->route('admin.users')
            ->with('status', 'User deleted: ' . $email);
    }

    /**
     * Start impersonation.
     */
    public function impersonate(Request $request, User $user)
    {
        $admin = $request->user();

        if ($admin && $admin->is($user)) {
            return back()->withErrors(['user' => 'You cannot impersonate yourself.']);
        }
        if (! $user->is_active) {
            return back()->withErrors(['user' => 'Inactive users cannot be impersonated.']);
        }
        if ($user->hasRole('Administrator')) {
            return back()->withErrors(['user' => 'Administrators cannot be impersonated.']);
        }

        session([
            'impersonate_as' => $user->id,
            'impersonator_id' => $admin?->id,
            'impersonation_started_at' => now()->getTimestamp(),
        ]);

        \App\Services\AuditLogger::logSystem('impersonation.start', "{$admin?->email} started impersonating {$user->email}.", [
            'service' => 'auth',
            'function' => 'impersonation',
            'impersonator_id' => $admin?->id,
            'impersonated_user_id' => $user->id,
        ]);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Now impersonating '.$user->email);
    }

    /**
     * Stop impersonation.
     */
    public function stopImpersonate(Request $request)
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonated = $request->user();

        \App\Services\AuditLogger::logSystem('impersonation.stop', "Impersonation of {$impersonated?->email} stopped.", [
            'service' => 'auth',
            'function' => 'impersonation',
            'impersonator_id' => $impersonatorId,
            'impersonated_user_id' => $impersonated?->id,
        ]);

        $request->session()->forget(['impersonate_as', 'impersonator_id', 'impersonation_started_at']);

        return redirect()
            ->route('admin.users')
            ->with('status', 'Stopped impersonating.');
    }

    /**
     * Roles that require MFA cannot opt out: force 'enforced' instead of 'disabled'.
     */
    private function effectiveMfaPreference(string $role, string $preference): string
    {
        $required = config('domaindash.mfa.enforce', true)
            && in_array($role, (array) config('domaindash.mfa.required_roles', []), true);

        return ($required && $preference === 'disabled') ? 'enforced' : $preference;
    }
}
