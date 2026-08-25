<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shop trades in Nepalese rupees. Application code always writes the
     * currency explicitly from Site settings, so this default is only a safety
     * net — but it should not say BDT.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 10)->default('NPR')->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('currency', 10)->default('NPR')->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 10)->default('BDT')->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('currency', 10)->default('BDT')->change();
        });
    }
};
