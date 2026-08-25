<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a payout is actually being sent.
     *
     * The row only ever stored the method code, so staff had nothing to pay
     * against and had to go and look the seller up. Worse, a seller editing
     * their bank details afterwards silently changed where an already-approved
     * payout appeared to be going. Copying the destination onto the payout —
     * the same way commission is snapshotted onto an order line — fixes both:
     * the panel can show it, and it is a record of where the money went.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('method');
            $table->string('account_name')->nullable()->after('bank_name');
            $table->string('account_number')->nullable()->after('account_name');
        });

        // Existing payouts predate the snapshot, so seed them from the seller
        // they belong to — the best record available for them.
        DB::table('payouts')
            ->join('sellers', 'sellers.id', '=', 'payouts.seller_id')
            ->update([
                'payouts.bank_name'      => DB::raw('sellers.payout_bank_name'),
                'payouts.account_name'   => DB::raw('sellers.payout_account_name'),
                'payouts.account_number' => DB::raw('sellers.payout_account_number'),
            ]);
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'account_name', 'account_number']);
        });
    }
};
