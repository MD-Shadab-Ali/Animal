<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery promise the buyer was given, kept with the order.
     *
     * The zone's estimate was shown while choosing a zone and then never again,
     * so the one moment it mattered — after paying, waiting for an animal — it
     * was gone.
     *
     * Snapshotted rather than read back off the zone, like the commission and
     * the address before it: an admin widening a zone to "5-7 days" must not
     * silently rewrite what an existing customer was promised, and zones are
     * `nullOnDelete` so a live one would take the estimate with it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_estimate')->nullable()->after('delivery_charge');
        });

        // Existing orders keep their zone's current wording — the closest thing
        // to what they were told that we still have.
        DB::table('orders')
            ->join('delivery_zones', 'delivery_zones.id', '=', 'orders.delivery_zone_id')
            ->whereNull('orders.delivery_estimate')
            ->update(['orders.delivery_estimate' => DB::raw('delivery_zones.estimated_time')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_estimate');
        });
    }
};
