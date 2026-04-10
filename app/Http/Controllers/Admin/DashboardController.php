<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        [$diskTotal, $diskFree, $diskUsed] = $this->diskMetrics();
        [$memTotalKB, $memUsedKB] = $this->memoryMetrics();
        $cpuUsage = $this->cpuUsage();

        $counts = [
            'domains' => DB::table('domains')->count(),
            'clients' => DB::table('clients')->count(),
            'users' => DB::table('users')->count(),
        ];

        $domainsWithoutClient = Domain::query()
            ->whereNull('client_id')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'status', 'expiry_date']);

        $domainsNotSyncedHalo = Domain::query()
            ->where(function ($query) {
                $query->whereNull('halo_asset_id')
                    ->orWhere('halo_asset_id', '');
            })
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'client_id', 'status']);

        $domainsNotSyncedItglue = Domain::query()
            ->where(function ($query) {
                $query->whereNull('itglue_id')
                    ->orWhere('itglue_id', '');
            })
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'client_id', 'status']);

        return view('admin.dashboard.index', compact(
            'diskTotal',
            'diskFree',
            'diskUsed',
            'memTotalKB',
            'memUsedKB',
            'cpuUsage',
            'counts',
            'domainsWithoutClient',
            'domainsNotSyncedHalo',
            'domainsNotSyncedItglue'
        ));
    }

    private function diskMetrics(): array
    {
        $diskTotal = disk_total_space('/') ?: 0;
        $diskFree = disk_free_space('/') ?: 0;
        $diskUsed = max(0, $diskTotal - $diskFree);

        return [$diskTotal, $diskFree, $diskUsed];
    }

    private function memoryMetrics(): array
    {
        $meminfo = file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $memTotalMatch);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $memAvailableMatch);

        $memTotalKB = (int) ($memTotalMatch[1] ?? 0);
        $memAvailKB = (int) ($memAvailableMatch[1] ?? 0);

        return [$memTotalKB, max(0, $memTotalKB - $memAvailKB)];
    }

    private function cpuUsage(): float
    {
        $loads = sys_getloadavg();
        $oneMinuteLoad = is_array($loads) ? (float) ($loads[0] ?? 0) : 0.0;

        $cpuCores = (int) trim((string) shell_exec('nproc 2>/dev/null'));
        if ($cpuCores <= 0) {
            $cpuCores = 1;
        }

        $percent = ($oneMinuteLoad / $cpuCores) * 100;

        return round(max(0, min(100, $percent)), 1);
    }
}
