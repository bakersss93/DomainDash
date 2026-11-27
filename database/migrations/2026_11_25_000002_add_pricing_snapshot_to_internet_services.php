<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plan and price snapshot taken when a service is ordered (later plan price changes do not rewrite history). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->foreignId('vocus_plan_id')->nullable()->after('plan_id')->constrained('vocus_plans')->nullOnDelete();
            $table->decimal('cost_price', 10, 2)->nullable()->after('vocus_plan_id');
            $table->decimal('sell_price', 10, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vocus_plan_id');
            $table->dropColumn(['cost_price', 'sell_price']);
        });
    }
};
