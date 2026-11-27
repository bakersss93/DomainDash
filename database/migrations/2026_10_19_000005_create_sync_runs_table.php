<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per sync run: powers "last synced", progress and Refresh now.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);              // halo|itglue
            $table->string('scope', 32);                 // clients|assets|domains|organizations|configurations
            $table->unsignedBigInteger('client_id')->nullable()->index(); // null = global
            $table->string('status', 16)->default('queued'); // queued|running|ok|partial|failed|skipped
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->timestamp('cursor_at')->nullable();  // high-water mark for incremental runs
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
