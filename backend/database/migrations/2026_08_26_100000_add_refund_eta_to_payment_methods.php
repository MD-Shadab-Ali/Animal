<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How long money takes to arrive on this rail.
     *
     * The storefront used to promise "a day or two" for every refund, which is
     * simply untrue of a wallet — an eSewa transfer lands while the buyer is
     * still reading the sentence. Telling someone to wait two days for money
     * that is already there earns a support call.
     *
     * Deliberately its own field rather than inferred from `requires_bank_name`:
     * that flag means "we need to know the bank", not "this settles slowly",
     * and the two come apart the moment a fast bank rail or a slow wallet
     * exists. It is admin-editable for the same reason.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('refund_eta')->nullable()->after('supports_payout');
        });

        // Defaults, not assumptions — an admin can correct any of them.
        DB::table('payment_methods')->whereIn('code', ['esewa', 'khalti'])
            ->update(['refund_eta' => 'straight away']);

        DB::table('payment_methods')->where('code', 'bank_transfer')
            ->update(['refund_eta' => 'in 1-3 working days']);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('refund_eta');
        });
    }
};
