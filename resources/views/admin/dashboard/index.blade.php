@extends('layouts.app')
@section('content')
<h1>Admin Dashboard</h1>
<div style="display:flex; gap:16px; flex-wrap:wrap;">
    <div>Disk: {{ number_format($diskUsed/1024/1024/1024,2) }} / {{ number_format($diskTotal/1024/1024/1024,2) }} GB</div>
    <div>Memory: {{ number_format($memUsedKB/1024/1024,2) }} / {{ number_format($memTotalKB/1024/1024,2) }} GB</div>
    <div>DB Size: {{ number_format($dbSize,2) }} MB</div>
</div>
<table border="1" cellpadding="6" cellspacing="0" style="margin-top:12px;">
    <tr><th>Domains</th><th>Clients</th><th>Users</th></tr>
    <tr><td>{{ $counts['domains'] }}</td><td>{{ $counts['clients'] }}</td><td>{{ $counts['users'] }}</td></tr>
</table>
<h3 style="margin-top:16px;">Synergy Balance</h3>
<pre style="background:#f9fafb; padding:8px;">{{ json_encode($balance) }}</pre>
@endsection
