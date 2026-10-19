<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Roles an API key may assign. Administrator is never assignable through the
     * API unless the key additionally carries the explicit users.admin scope.
     */
    private function assignableRoles(Request $request): array
    {
        $key = $request->attributes->get('api_key');
        $roles = ['Technician', 'Customer'];

        if ($key && $key->allowsScope('users.admin')) {
            $roles[] = 'Administrator';
        }

        // Only roles that actually exist.
        return array_values(array_intersect($roles, Role::pluck('name')->all()));
    }

    private function canManageTarget(Request $request, User $user): bool
    {
        $key = $request->attributes->get('api_key');

        return ! $user->hasRole('Administrator') || ($key && $key->allowsScope('users.admin'));
    }

    public function index(Request $request): JsonResponse
    {
        $q = User::with('roles');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn($query) => $query->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }

        $paginated = $q->orderBy('name')->paginate(min(100, max(1, (int) $request->get('per_page', 100))));

        $paginated->getCollection()->transform(fn($user) => $this->format($user));

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'confirmed'],
            'password_confirmation' => 'required|string',
            'is_active'             => 'nullable|boolean',
            'role'                  => ['nullable', 'string', Rule::in($this->assignableRoles($request))],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
            'mfa_preference' => 'enforced', // API-created users must enrol MFA
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        if (!empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        \App\Services\AuditLogger::logSystem('user.create', "User {$user->email} created via API.", [
            'service' => 'api', 'function' => 'user.create',
        ], ['new_values' => ['id' => $user->id, 'email' => $user->email, 'role' => $data['role'] ?? null]]);

        return response()->json($this->format($user->load('roles')), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->format($user->load('roles')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if (! $this->canManageTarget($request, $user)) {
            return response()->json(['message' => 'Administrator accounts cannot be modified through the API.'], 403);
        }

        $data = $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'email'     => 'sometimes|required|email|unique:users,email,' . $user->id,
            'is_active' => 'nullable|boolean',
            'role'      => ['nullable', 'string', Rule::in($this->assignableRoles($request))],
        ]);

        $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'is_active'])));
        $user->save();

        if (array_key_exists('role', $data)) {
            $user->syncRoles($data['role'] ? [$data['role']] : []);
        }

        return response()->json($this->format($user->load('roles')));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (! $this->canManageTarget($request, $user)) {
            return response()->json(['message' => 'Administrator accounts cannot be deleted through the API.'], 403);
        }

        if ($user->hasRole('Administrator') && User::role('Administrator')->count() <= 1) {
            return response()->json(['message' => 'The last administrator cannot be deleted.'], 422);
        }

        \App\Services\AuditLogger::logSystem('user.delete', "User {$user->email} deleted via API.", [
            'service' => 'api', 'function' => 'user.delete',
        ], ['old_values' => ['id' => $user->id, 'email' => $user->email]]);

        $user->delete();

        return response()->json(null, 204);
    }

    private function format(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'is_active'      => (bool) $user->is_active,
            'mfa_preference' => $user->mfa_preference,
            'roles'          => $user->roles->pluck('name'),
            'created_at'     => $user->created_at,
            'updated_at'     => $user->updated_at,
        ];
    }
}
