<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Off by default: a client's own users may run read-only diagnostics on their internet services only when staff enable it.
            $table->boolean('internet_diagnostics_enabled')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('internet_diagnostics_enabled');
        });
    }
};
