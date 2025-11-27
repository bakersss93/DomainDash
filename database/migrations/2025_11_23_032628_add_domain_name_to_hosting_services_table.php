<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_services', function (Blueprint $table) {
            // If you want this near the top, you can use ->after('id') etc.
            $table->string('domain_name')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('hosting_services', function (Blueprint $table) {
            $table->dropColumn('domain_name');
        });
    }
};
