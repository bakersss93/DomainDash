<?php

use App\Services\Monitoring\VocusTransactionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured, indexed columns for Vocus WSM calls (certification journeys, search by transaction id).
 * Nothing secret is ever stored here: identifiers and codes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_requests', function (Blueprint $table) {
            $table->string('environment', 12)->nullable()->after('operation');          // sandbox | production
            $table->string('product_id', 24)->nullable()->after('environment');         // FIBRE, DIR, TCAS, OPER, NETWORK
            $table->string('plan_id', 40)->nullable()->after('product_id');
            $table->string('scope', 40)->nullable()->after('plan_id');                  // QUALIFY, SERVICE, RESPONSE ...
            $table->string('transaction_id', 40)->nullable()->after('scope');           // from the response or fault
            $table->string('related_transaction_id', 40)->nullable()->after('transaction_id'); // TCAS poll: the transaction asked about
            $table->string('response_type', 12)->nullable()->after('related_transaction_id'); // SYNC | ASYNC | QUEUED
            $table->string('result', 40)->nullable()->after('response_type');           // OK or the fault code
            $table->string('directory_id', 40)->nullable()->after('result');            // DirectoryID / LOC id
            $table->string('service_id', 40)->nullable()->after('directory_id');
            $table->string('avc_id', 40)->nullable()->after('service_id');
            $table->json('meta')->nullable()->after('avc_id');                          // order_type, transaction_state, directory_ids

            $table->index('transaction_id', 'ir_transaction');
            $table->index('related_transaction_id', 'ir_related_transaction');
            $table->index('service_id', 'ir_service');
            $table->index('directory_id', 'ir_directory');
            $table->index(['provider', 'environment', 'created_at'], 'ir_provider_env_created');
        });

        // Best effort: fill the new columns for Vocus rows recorded before this migration. Never fails the migration.
        try {
            (new VocusTransactionBackfill())->run();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        Schema::table('integration_requests', function (Blueprint $table) {
            $table->dropIndex('ir_transaction');
            $table->dropIndex('ir_related_transaction');
            $table->dropIndex('ir_service');
            $table->dropIndex('ir_directory');
            $table->dropIndex('ir_provider_env_created');
            $table->dropColumn(['environment', 'product_id', 'plan_id', 'scope', 'transaction_id', 'related_transaction_id',
                'response_type', 'result', 'directory_id', 'service_id', 'avc_id', 'meta']);
        });
    }
};
