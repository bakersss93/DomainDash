<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 8);                    // outbound | inbound
            $table->string('provider', 24);                    // halo|itglue|synergy|vocus|ip2whois|api
            $table->string('method', 10)->nullable();          // GET, POST ... or SOAP
            $table->string('operation', 120)->nullable();      // SOAP operation / short label
            $table->string('url', 1000)->nullable();           // query secrets stripped
            $table->json('request_headers')->nullable();       // masked
            $table->mediumText('request_body')->nullable();    // redacted + truncated
            $table->boolean('request_truncated')->default(false);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();      // allow-listed subset
            $table->mediumText('response_body')->nullable();   // redacted + truncated
            $table->boolean('response_truncated')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('entity_type', 64)->nullable();     // domain, client, internet_service ...
            $table->string('reference', 191)->nullable();      // domain name, client name, service id
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('actor', 120)->nullable();          // inbound: API key name
            $table->string('ip', 64)->nullable();              // inbound: caller IP
            $table->boolean('is_error')->default(false);
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['direction', 'created_at'], 'ir_direction_created');
            $table->index(['direction', 'provider', 'created_at'], 'ir_direction_provider_created');
            $table->index(['is_error', 'created_at'], 'ir_error_created');
            $table->index('reference', 'ir_reference');
            $table->index('sync_run_id', 'ir_sync_run');
        });

        // audit_logs already indexes action and created_at separately; the Activity tab filters on both.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at'], 'audit_logs_action_created_index');
        });

        // Show the last 30 days of existing API key traffic in the new Inbound tab (no bodies were kept then).
        if (Schema::hasTable('api_access_logs')) {
            DB::table('api_access_logs')
                ->where('requested_at', '>=', now()->subDays(30))
                ->orderBy('id')
                ->chunk(500, function ($rows) {
                    $keys = DB::table('api_keys')->whereIn('id', $rows->pluck('api_key_id')->filter()->unique())->pluck('name', 'id');
                    DB::table('integration_requests')->insert($rows->map(fn ($r) => [
                        'direction' => 'inbound',
                        'provider' => 'api',
                        'method' => $r->method,
                        'url' => '/' . ltrim((string) $r->path, '/'),
                        'response_status' => $r->status,
                        'api_key_id' => $r->api_key_id,
                        'actor' => $r->api_key_id ? ($keys[$r->api_key_id] ?? null) : null,
                        'ip' => $r->ip,
                        'request_headers' => json_encode(array_filter(['user-agent' => $r->user_agent])),
                        'is_error' => (int) $r->status >= 400,
                        'created_at' => $r->requested_at,
                    ])->all());
                });
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_created_index');
        });
        Schema::dropIfExists('integration_requests');
    }
};
