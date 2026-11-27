<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeric plan speeds (Mbps) so plans a line cannot carry (FTTN / FTTB / FTTC) can be hidden and refused.
 * Existing rows are backfilled from the free-text speed_tier when it looks like "100/20" or "100/20 Mbps".
 * Plans that cannot be parsed stay NULL (unknown speed: never restricted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocus_plans', function (Blueprint $table) {
            $table->unsignedInteger('download_mbps')->nullable()->after('speed_tier');
            $table->unsignedInteger('upload_mbps')->nullable()->after('download_mbps');
        });

        foreach (DB::table('vocus_plans')->whereNotNull('speed_tier')->get(['id', 'speed_tier']) as $row) {
            if (! preg_match('/^\s*(\d{1,5})\s*\/\s*(\d{1,5})\s*(?:mbps|mb\/s|mb|m)?\s*$/i', (string) $row->speed_tier, $m)) {
                continue;
            }
            [$down, $up] = [(int) $m[1], (int) $m[2]];
            if ($down < 1 || $down > 10000 || $up < 1 || $up > 10000) {
                continue;
            }
            DB::table('vocus_plans')->where('id', $row->id)->update(['download_mbps' => $down, 'upload_mbps' => $up]);
        }
    }

    public function down(): void
    {
        Schema::table('vocus_plans', function (Blueprint $table) {
            $table->dropColumn(['download_mbps', 'upload_mbps']);
        });
    }
};
