<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vocus WSM audit (docs/review/13-vocus-wsm-audit.md):
 *  - VW-10: the Get SERVICE "Username" (2..100 chars, e.g. user@realm) was stored in the
 *           64-char `realm` column. It gets its own column; `realm` keeps the realm only.
 *  - VW-05: persist the TCAS faultcode / faultmessage of a FAILED transaction.
 *  - VW-18: remember when the transaction was last polled (poll cool-down).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->after('realm');
            $table->string('last_fault_code', 40)->nullable()->after('last_transaction_state');
            $table->string('last_fault_message', 500)->nullable()->after('last_fault_code');
            $table->timestamp('last_polled_at')->nullable()->after('last_fault_message');
        });

        // Rows synced before this migration stored "user@realm" in `realm`: split it.
        DB::table('internet_services')
            ->where('realm', 'like', '%@%')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $realm = substr((string) $row->realm, strpos((string) $row->realm, '@') + 1);

                    DB::table('internet_services')->where('id', $row->id)->update([
                        'username' => $row->realm,
                        'realm' => substr($realm, 0, 64),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn(['username', 'last_fault_code', 'last_fault_message', 'last_polled_at']);
        });
    }
};
