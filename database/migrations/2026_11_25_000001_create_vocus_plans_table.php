<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vocus plans managed in Settings -> Vocus -> Plans. `plan_id` is the PlanID sent to Vocus
 * (FIBRE::Create, 30 characters at most). Prices are AUD per month, 2 decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocus_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_id', 30)->unique();
            $table->string('name', 100);
            $table->json('scopes')->nullable();          // null / empty = valid for every enabled scope
            $table->string('speed_tier', 40)->nullable(); // e.g. "100/20 Mbps"
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->decimal('sell_price', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocus_plans');
    }
};
