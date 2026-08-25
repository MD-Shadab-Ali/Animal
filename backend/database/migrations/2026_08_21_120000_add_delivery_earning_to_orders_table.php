<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery charge sits on the order, not on a line, so it needs its own
     * attribution. When one seller supplies the whole order they also deliver
     * it, and the charge is theirs. Otherwise the platform delivers and keeps it.
     *
     * Kept separate from `order_items.seller_earning` so per-line maths stays
     * auditable: a line's earning is always its own total minus its commission.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_seller_id')->nullable()->after('delivery_charge')
                ->constrained('sellers')->nullOnDelete();

            $table->decimal('delivery_earning', 12, 2)->default(0)->after('delivery_seller_id');

            $table->foreignId('delivery_payout_id')->nullable()->after('delivery_earning')
                ->constrained('payouts')->nullOnDelete();

            $table->index(['delivery_seller_id', 'delivery_payout_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['delivery_seller_id', 'delivery_payout_id']);
            $table->dropConstrainedForeignId('delivery_seller_id');
            $table->dropConstrainedForeignId('delivery_payout_id');
            $table->dropColumn('delivery_earning');
        });
    }
};
