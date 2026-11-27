<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionsController extends Controller
{
    /**
     * Permissions that only internal staff roles (Administrator / Technician) may hold. Customer
     * and any custom client-facing role can never be granted these, even by an administrator.
     */
    public const STAFF_ONLY_PERMISSIONS = [
        'domains.manage', 'domains.transfer', 'domains.register', 'domains.renew',
        'services.manage', 'ssl.manage', 'internet.manage',
        'clients.view', 'clients.manage', 'users.view', 'users.manage', 'users.impersonate',
        'settings.manage', 'apikeys.manage', 'analytics.view', 'sync.run',
        'domain-pricing.view', 'domain-pricing.manage',
    ];

    private function isStaffRole(string $name): bool
    {
        return in_array($name, ['Administrator', 'Technician'], true);
    }

    public function index()
    {
        // Ensure recently added permission keys are present in existing environments
        // where seeders may not have been re-run.
        foreach (['domain-pricing.view', 'domain-pricing.manage'] as $permissionName) {
            Permission::findOrCreate($permissionName);
        }

        $roles = Role::orderBy('name')->get();
        $permissions = Permission::orderBy('name')->get();

        return view('admin.permissions.index', compact('roles','permissions'));
    }

    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')],
        ]);

        Role::create(['name' => trim($data['name'])]);

        return back()->with('status', 'Role created.');
    }

    public function updateRolePermissions(Request $request, Role $role)
    {
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $granted = $data['permissions'] ?? [];

        if (! $this->isStaffRole($role->name)) {
            $forbidden = array_values(array_intersect($granted, self::STAFF_ONLY_PERMISSIONS));
            if ($forbidden !== []) {
                return back()->withErrors(['permissions' => 'These permissions are staff-only and cannot be granted to '.$role->name.': '.implode(', ', $forbidden).'.']);
            }
        }

        $role->syncPermissions($granted);

        return back()->with('status', 'Permissions updated for '.$role->name.'.');
    }

    public function destroyRole(Role $role)
    {
        if ($role->name === 'Administrator') {
            return back()->with('status', 'Administrator role cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('status', 'Cannot delete role with assigned users.');
        }

        $role->delete();

        return back()->with('status', 'Role deleted.');
    }

    public function update(Request $request)
    {
        $data = $request->validate(['permissions'=>'array']);
        foreach (['Technician','Customer'] as $roleName) {
            $role = Role::where('name',$roleName)->firstOrFail();
            $granted = array_keys($data['permissions'][$roleName] ?? []);
            if (! $this->isStaffRole($roleName)) {
                $forbidden = array_values(array_intersect($granted, self::STAFF_ONLY_PERMISSIONS));
                if ($forbidden !== []) {
                    return back()->withErrors(['permissions' => 'These permissions are staff-only and cannot be granted to '.$roleName.': '.implode(', ', $forbidden).'.']);
                }
            }
            $role->syncPermissions($granted);
        }
        return back()->with('status','Permissions updated');
    }
}
