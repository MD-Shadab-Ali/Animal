<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment method has two independent jobs: taking money from a buyer at
     * checkout, and sending money out to a seller. `is_active` only ever meant
     * the first one, which is why switching on eSewa did nothing for payouts.
     * This flag says the method can also be paid *out* through.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('supports_payout')->default(false)->after('is_active');
        });

        // Backfill the obvious answer so existing installs are not left with a
        // second switch nobody knows to flip: every rail except cash on
        // delivery can carry money in both directions.
        DB::table('payment_methods')->where('code', '!=', 'cod')->update(['supports_payout' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('supports_payout');
        });
    }
};
