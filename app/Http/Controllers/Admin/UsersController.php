<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show create user form.
     */
    public function create()
    {
        $clients = Client::orderBy('business_name')->get();

        return view('admin.users.create', compact('clients'));
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
            'role'         => 'required|in:Administrator,Technician,Customer',
            'client_ids'   => 'array',
            'client_ids.*' => 'integer|exists:clients,id',
            'password'     => 'nullable|string|min:8|confirmed',
        ]);

        $password = $data['password'] ?: Str::random(12);

        $user = User::create([
            'name'     => trim($data['first_name'].' '.$data['last_name']),
            'email'    => $data['email'],
            'password' => Hash::make($password),
        ]);

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
    public function edit(User $user)
    {
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
            'last'
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
            'role'         => 'required|in:Administrator,Technician,Customer',
            'client_ids'   => 'array',
            'client_ids.*' => 'integer|exists:clients,id',
        ]);

        $user->update([
            'name'  => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
        ]);

        $user->syncRoles([$data['role']]);
        $user->clients()->sync($data['client_ids'] ?? []);

        return redirect()
            ->route('admin.users')
            ->with('status', 'User updated successfully.');
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
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

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
        ])->save();

        return back()->with('status', 'MFA reset for '.$user->email.'. They will need to re-enrol.');
    }

    /**
     * Start impersonation.
     */
    public function impersonate(User $user)
    {
        session(['impersonate_as' => $user->id]);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Now impersonating '.$user->email);
    }

    /**
     * Stop impersonation.
     */
    public function stopImpersonate(Request $request)
    {
        $request->session()->forget('impersonate_as');

        return redirect()
            ->route('admin.users')
            ->with('status', 'Stopped impersonating.');
    }
}
