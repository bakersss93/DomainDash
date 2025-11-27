@extends('layouts.app')
@section('content')
<h1>Permissions</h1>
<form method="POST" action="{{ route('admin.permissions.update') }}">
@csrf
<table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>Permission</th><th>Technician</th><th>Customer</th></tr></thead>
    <tbody>
    @foreach($permissions as $p)
        <tr>
            <td>{{ $p->name }}</td>
            @foreach($roles as $r)
                <td style="text-align:center">
                    <input type="checkbox" name="permissions[{{ $r->name }}][{{ $p->name }}]" 
                        @checked($r->hasPermissionTo($p->name))>
                </td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
<button type="submit">Save</button>
</form>
@endsection
