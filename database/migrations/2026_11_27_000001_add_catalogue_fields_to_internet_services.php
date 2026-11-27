<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the FIBRE catalogue defines on Create / Get SERVICE that the service record did not keep:
 * AuthType (PPPoE | IPoE), IPAssignment (CGNAT | STATIC | DYNAMIC), IPAddress, Reference (customer reference),
 * CentralSplitter (FTTB/N), CTAG and Label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->string('auth_type', 10)->nullable()->after('service_level');
            $table->string('ip_assignment', 10)->nullable()->after('auth_type');
            $table->string('ip_address', 46)->nullable()->after('ip_assignment');
            $table->string('reference', 30)->nullable()->after('ip_address');
            $table->string('central_splitter', 5)->nullable()->after('reference');
            // OrderType values are NEW, TRANSFER and OUTSTANDING (11 characters).
            $table->string('order_type', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn(['auth_type', 'ip_assignment', 'ip_address', 'reference', 'central_splitter']);
            $table->string('order_type', 10)->nullable()->change();
        });
    }
};
