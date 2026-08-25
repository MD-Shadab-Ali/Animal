<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money coming in, as a ledger rather than two columns on the order.
     *
     * `orders.paid_amount` could say *how much* had arrived but never how it
     * got there — an advance and a balance are two events, from possibly two
     * methods, on two days, and one of them might be disputed. Payouts (money
     * out) already had a ledger; this is the other half of the book, and it is
     * what makes "how much came in, from whom, through what" answerable.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Who the money came from. Null for a walk-in staff recorded by hand.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('method');            // payment_methods.code
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('NPR');

            // A refund is the same event pointing the other way, so it lives in
            // the same ledger and simply subtracts.
            $table->enum('type', ['payment', 'refund'])->default('payment');

            // Customer-submitted money is a *claim* until staff have seen it
            // land. Staff-recorded money is confirmed the moment it is entered.
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->enum('source', ['customer', 'staff'])->default('customer');

            $table->string('transaction_reference')->nullable();
            $table->string('proof')->nullable();   // screenshot or deposit slip
            $table->text('note')->nullable();

            $table->timestamp('paid_at')->nullable();     // when the payer says they sent it
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Buyer-facing payee details. `config` already exists but is hidden and
        // holds gateway secrets; these are meant to be shown to the customer.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('payee_account_name')->nullable()->after('requires_bank_name');
            $table->string('payee_account_number')->nullable()->after('payee_account_name');
            $table->string('payee_bank_name')->nullable()->after('payee_account_number');
            $table->string('payee_qr_image')->nullable()->after('payee_bank_name');
        });

        DB::table('settings')->insertOrIgnore([
            [
                'group'       => 'marketplace',
                'key'         => 'auto_deliver_on_payment',
                'value'       => '1',
                'type'        => 'boolean',
                'label'       => 'Mark orders delivered once paid',
                'hint'        => 'When the balance is settled on an order already out for delivery, close it automatically instead of asking staff to.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn([
                'payee_account_name', 'payee_account_number', 'payee_bank_name', 'payee_qr_image',
            ]);
        });

        DB::table('settings')->where('key', 'auto_deliver_on_payment')->delete();
    }
};
