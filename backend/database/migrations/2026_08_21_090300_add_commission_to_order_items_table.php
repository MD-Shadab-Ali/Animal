<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commission is snapshotted per line at purchase time. Rates change, and a
     * settled sale must never move because someone edited a seller afterwards.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->after('goat_id')
                ->constrained()->nullOnDelete();
            $table->string('seller_name')->nullable()->after('seller_id');

            $table->decimal('commission_rate', 5, 2)->default(0)->after('line_total');
            $table->decimal('commission_amount', 12, 2)->default(0)->after('commission_rate');
            $table->decimal('seller_earning', 12, 2)->default(0)->after('commission_amount');

            $table->foreignId('payout_id')->nullable()->after('seller_earning')
                ->constrained()->nullOnDelete();

            $table->index(['seller_id', 'payout_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropConstrainedForeignId('payout_id');
            $table->dropColumn(['seller_name', 'commission_rate', 'commission_amount', 'seller_earning']);
        });
    }
};
