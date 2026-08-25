<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a refund is being sent back to.
     *
     * Money coming in needs no destination — it is already ours. Money going
     * back does, and it is not necessarily the account it arrived from: a
     * wallet transfer may need returning to a bank, and the buyer is the only
     * one who can say where. Refunds live in the same ledger as payments and
     * simply subtract, so the columns hang off that table.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_to_name')->nullable()->after('proof');
            $table->string('refund_to_account')->nullable()->after('refund_to_name');
            $table->string('refund_to_bank')->nullable()->after('refund_to_account');
            $table->text('refund_reason')->nullable()->after('refund_to_bank');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refund_to_name', 'refund_to_account', 'refund_to_bank', 'refund_reason']);
        });
    }
};
