<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vocus bundle plans are specific to a technology (FTTP, FTTB, FTTN, FTTC, HFC, WIRELESS) and to an account
 * mapping (TAGGED or DEFAULT_MAP). Both are nullable: an existing plan with no technology / mapping stays offered
 * for every technology / mapping, exactly as before. plan_id stays unique (the sheet ids are unique, "-DM" included).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocus_plans', function (Blueprint $table) {
            $table->string('technology', 20)->nullable()->after('speed_tier');
            $table->string('mapping', 20)->nullable()->after('technology');
            $table->index(['technology', 'mapping']);
        });
    }

    public function down(): void
    {
        Schema::table('vocus_plans', function (Blueprint $table) {
            $table->dropIndex(['technology', 'mapping']);
            $table->dropColumn(['technology', 'mapping']);
        });
    }
};
