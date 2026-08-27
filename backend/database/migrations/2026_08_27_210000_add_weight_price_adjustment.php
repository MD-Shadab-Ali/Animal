<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the weight at the door did to the bill.
 *
 * Kept beside the agreed figures rather than replacing them: `line_total` and
 * `subtotal` stay as the buyer agreed them, and these carry the signed
 * difference the scale made. Overwriting the originals would leave nothing to
 * show a buyer who asks why the amount moved.
 *
 * Only the goats are recalculated. The coupon and the delivery charge were
 * part of the deal struck at checkout, and re-running those rules at the door
 * would change terms nobody agreed to -- a lighter goat could end up costing
 * more to deliver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'price_adjustment')) {
                // Signed: negative when the goat came in lighter.
                $table->decimal('price_adjustment', 12, 2)->default(0)->after('line_total');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'weight_adjustment')) {
                $table->decimal('weight_adjustment', 12, 2)->default(0)->after('subtotal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'price_adjustment')) {
                $table->dropColumn('price_adjustment');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'weight_adjustment')) {
                $table->dropColumn('weight_adjustment');
            }
        });
    }
};
