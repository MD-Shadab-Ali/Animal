<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Put lines back in step with the order they belong to.
     *
     * Confirming an order used to drag every line to "preparing", so a buyer
     * saw an amber "Preparing the animal" beneath a timeline still reading
     * Confirmed. The rule is fixed; these rows were stamped before it was.
     *
     * Safe to reverse because a seller genuinely starting work rolls the order
     * up to `processing` — so a line sitting at `preparing` under a `pending`
     * or `confirmed` order can only have been put there by the old carry-down,
     * never by anybody actually preparing an animal.
     */
    public function up(): void
    {
        DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.fulfilment_status', 'preparing')
            ->whereIn('orders.status', ['pending', 'confirmed'])
            ->update([
                'order_items.fulfilment_status'     => 'pending',
                'order_items.fulfilment_updated_at' => now(),
            ]);
    }

    /** Nothing to undo: the old state was the wrong one. */
    public function down(): void
    {
        //
    }
};
