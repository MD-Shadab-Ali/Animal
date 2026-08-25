<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An order can hold goats from several sellers at once, so the single
     * `orders.status` cannot be handed to any one of them. Each line gets its
     * own fulfilment state that its seller owns, while the order-level delivery
     * status stays with the platform that actually does the delivering.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->enum('fulfilment_status', [
                'pending', 'preparing', 'ready', 'handed_over', 'cancelled',
            ])->default('pending')->after('seller_name');

            $table->text('fulfilment_note')->nullable()->after('fulfilment_status');
            $table->timestamp('fulfilment_updated_at')->nullable()->after('fulfilment_note');

            $table->index(['seller_id', 'fulfilment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['seller_id', 'fulfilment_status']);
            $table->dropColumn(['fulfilment_status', 'fulfilment_note', 'fulfilment_updated_at']);
        });
    }
};
