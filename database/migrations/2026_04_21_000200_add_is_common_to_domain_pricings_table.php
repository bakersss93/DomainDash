<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_pricings', function (Blueprint $table) {
            $table->boolean('is_common')->default(false)->after('sell_price');
        });
    }

    public function down(): void
    {
        Schema::table('domain_pricings', function (Blueprint $table) {
            $table->dropColumn('is_common');
        });
    }
};
