<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(SynergyWholesaleClient $synergy)
    {
        // system metrics
        $diskTotal = @disk_total_space('/') ?: 0;
        $diskFree = @disk_free_space('/') ?: 0;
        $diskUsed = $diskTotal - $diskFree;
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+)/',$meminfo,$m1); preg_match('/MemAvailable:\s+(\d+)/',$meminfo,$m2);
        $memTotalKB = (int)($m1[1] ?? 0); $memAvailKB = (int)($m2[1] ?? 0);
        $memUsedKB = max(0, $memTotalKB - $memAvailKB);

        // database size (MySQL)
        $dbName = DB::getDatabaseName();
        $dbSize = DB::selectOne("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) size_mb FROM information_schema.tables WHERE table_schema = ?", [$dbName])->size_mb ?? 0;

        $counts = [
            'domains' => DB::table('domains')->count(),
            'clients' => DB::table('clients')->count(),
            'users'   => DB::table('users')->count(),
        ];

        $balance = null;
        try { $balance = $synergy->balanceQuery(); } catch (\Throwable $e) { $balance = ['status'=>'error','errorMessage'=>$e->getMessage()]; }

        return view('admin.dashboard.index', compact('diskTotal','diskUsed','memTotalKB','memUsedKB','dbSize','counts','balance'));
    }
}
