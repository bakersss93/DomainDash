<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_pricings', function (Blueprint $table) {
            $table->id();
            $table->string('tld')->unique();
            $table->decimal('registration_price', 10, 2)->nullable();
            $table->decimal('renewal_price', 10, 2)->nullable();
            $table->decimal('restore_price', 10, 2)->nullable();
            $table->decimal('transfer_price', 10, 2)->nullable();
            $table->unsignedTinyInteger('minimum_years')->nullable();
            $table->unsignedTinyInteger('maximum_years')->nullable();
            $table->boolean('id_protection')->default(false);
            $table->boolean('dnssec')->default(false);
            $table->decimal('sale_registration_1_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_2_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_3_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_4_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_5_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_6_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_7_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_8_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_9_year_price', 10, 2)->nullable();
            $table->decimal('sale_registration_10_year_price', 10, 2)->nullable();
            $table->decimal('sale_renew_price', 10, 2)->nullable();
            $table->decimal('sale_transfer_price', 10, 2)->nullable();
            $table->date('sale_end_date')->nullable();
            $table->decimal('sell_price', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_pricings');
    }
};
