<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionsController extends Controller
{
    public function index()
    {
        $roles = Role::whereIn('name',['Technician','Customer'])->get();
        $permissions = Permission::orderBy('name')->get();
        return view('admin.permissions.index', compact('roles','permissions'));
    }

    public function update(Request $request)
    {
        $data = $request->validate(['permissions'=>'array']);
        foreach (['Technician','Customer'] as $roleName) {
            $role = Role::where('name',$roleName)->firstOrFail();
            $granted = array_keys($data['permissions'][$roleName] ?? []);
            $role->syncPermissions($granted);
        }
        return back()->with('status','Permissions updated');
    }
}
