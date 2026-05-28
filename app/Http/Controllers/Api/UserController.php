<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::with('roles');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn($query) => $query->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }

        $paginated = $q->orderBy('name')->paginate((int) $request->get('per_page', 100));

        $paginated->getCollection()->transform(fn($user) => $this->format($user));

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
            'is_active'             => 'nullable|boolean',
            'role'                  => 'nullable|string',
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (!empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($this->format($user->load('roles')), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->format($user->load('roles')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'email'     => 'sometimes|required|email|unique:users,email,' . $user->id,
            'is_active' => 'nullable|boolean',
            'role'      => 'nullable|string',
        ]);

        $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'is_active'])));
        $user->save();

        if (array_key_exists('role', $data)) {
            $user->syncRoles($data['role'] ? [$data['role']] : []);
        }

        return response()->json($this->format($user->load('roles')));
    }

    public function destroy(User $user): JsonResponse
    {
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
