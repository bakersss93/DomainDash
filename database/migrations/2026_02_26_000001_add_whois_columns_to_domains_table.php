<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            if (!Schema::hasColumn('domains', 'whois_data')) {
                $table->longText('whois_data')->nullable()->after('itglue_id');
            }

            if (!Schema::hasColumn('domains', 'whois_synced_at')) {
                $table->timestamp('whois_synced_at')->nullable()->after('whois_data');
                $table->index('whois_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            if (Schema::hasColumn('domains', 'whois_synced_at')) {
                $table->dropIndex(['whois_synced_at']);
                $table->dropColumn('whois_synced_at');
            }

            if (Schema::hasColumn('domains', 'whois_data')) {
                $table->dropColumn('whois_data');
            }
        });
    }
};
