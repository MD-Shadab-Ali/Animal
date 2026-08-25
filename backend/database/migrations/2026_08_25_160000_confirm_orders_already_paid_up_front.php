<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Catch up orders whose money arrived before the rule existed.
     *
     * Confirming a payment now confirms the order, but that only fires when a
     * payment is confirmed — so anything settled beforehand was left sitting at
     * "Placed" with the cash already in hand. This brings that history into
     * line with the rule the app now enforces.
     *
     * Done at the database level on purpose: a migration must not fire status
     * e-mails at customers about orders they placed days ago, and it may well
     * be run against a staging copy. The history row is written by hand so the
     * change is still auditable rather than appearing from nowhere.
     */
    public function up(): void
    {
        $orders = DB::table('orders')
            ->where('status', 'pending')
            ->where('paid_amount', '>', 0)
            ->where(function ($query) {
                $query->whereNull('advance_required')
                    ->orWhereColumn('paid_amount', '>=', 'advance_required');
            })
            ->whereNull('deleted_at')
            ->get(['id']);

        foreach ($orders as $order) {
            DB::table('orders')->where('id', $order->id)->update([
                'status'     => 'confirmed',
                'updated_at' => now(),
            ]);

            DB::table('order_status_histories')->insert([
                'order_id'    => $order->id,
                'user_id'     => null,
                'from_status' => 'pending',
                'to_status'   => 'confirmed',
                'note'        => 'Confirmed automatically: payment had already been received.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /**
     * Left alone on purpose. Reversing this would push a paid order back to
     * "Placed", which is less true than where it is now.
     */
    public function down(): void
    {
        //
    }
};
