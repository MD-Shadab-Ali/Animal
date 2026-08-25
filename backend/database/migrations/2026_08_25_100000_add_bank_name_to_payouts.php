<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A wallet number identifies the account on its own; a bank account number
     * does not — it means nothing without the bank it belongs to. Rather than
     * hardcoding which method that is, the method itself says whether it needs
     * one, so a new bank added in the admin panel behaves correctly with no
     * code change.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('requires_bank_name')->default(false)->after('supports_payout');
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->string('payout_bank_name')->nullable()->after('payout_method');
        });

        DB::table('payment_methods')
            ->where('code', 'bank_transfer')
            ->update(['requires_bank_name' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('requires_bank_name');
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('payout_bank_name');
        });
    }
};
