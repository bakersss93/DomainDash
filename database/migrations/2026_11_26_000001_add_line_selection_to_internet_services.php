<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NTD id, UNI-D port and NBN service class chosen / reported for an NBN service (copper_pair_id already exists). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->string('ntd_id', 30)->nullable()->after('copper_pair_id');
            $table->string('uni_port', 30)->nullable()->after('ntd_id');
            $table->string('service_class', 10)->nullable()->after('uni_port');
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn(['ntd_id', 'uni_port', 'service_class']);
        });
    }
};
