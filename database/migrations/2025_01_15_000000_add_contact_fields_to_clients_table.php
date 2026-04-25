<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('primary_contact_name')->nullable()->after('business_name');
            $table->string('email')->nullable()->after('primary_contact_name');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('address')->nullable()->after('phone');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postcode', 20)->nullable()->after('state');
            $table->string('country', 2)->default('AU')->after('postcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'primary_contact_name',
                'email',
                'phone',
                'address',
                'city',
                'state',
                'postcode',
                'country',
            ]);
        });
    }
};
