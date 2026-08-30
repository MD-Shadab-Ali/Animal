<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a payment gateway told us, kept beside what the buyer claimed.
 *
 * Until now every payment row was somebody's word: a buyer saying they sent
 * money, or staff saying they saw it arrive. A gateway-backed payment is
 * neither -- it is a transaction we can re-ask the provider about at any time,
 * so it needs the identifiers to ask with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Which provider, and our own id for the attempt. gateway_ref is
            // eSewa's transaction_uuid or Khalti's pidx -- generated before the
            // buyer leaves, so an abandoned attempt is still traceable.
            $table->string('gateway')->nullable()->after('method');
            $table->string('gateway_ref')->nullable()->after('gateway');

            // Their id for the money, known only once it has actually moved.
            $table->string('gateway_txn_id')->nullable()->after('gateway_ref');

            // Their word, verbatim, plus the whole verification response. Kept
            // so a dispute can be answered without calling the provider again.
            $table->string('gateway_status')->nullable()->after('gateway_txn_id');
            $table->json('gateway_payload')->nullable()->after('gateway_status');

            // The redirect, a webhook and an impatient refresh can all report
            // the same payment. One attempt, one row.
            $table->unique(['gateway', 'gateway_ref'], 'payments_gateway_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_gateway_ref_unique');
            $table->dropColumn([
                'gateway', 'gateway_ref', 'gateway_txn_id', 'gateway_status', 'gateway_payload',
            ]);
        });
    }
};
