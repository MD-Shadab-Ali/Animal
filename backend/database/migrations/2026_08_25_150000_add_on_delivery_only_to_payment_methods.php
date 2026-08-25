<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A method that settles the balance at the door but cannot start an order.
     *
     * Cash on delivery is not really a way to *place* an order — it is how the
     * remainder is handed over once the goat arrives. Letting a buyer pick it
     * at checkout means the shop reserves an animal against nothing at all,
     * which is exactly what the advance is there to prevent. It stays visible
     * so the buyer understands how they will settle up, but greyed out.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('on_delivery_only')->default(false)->after('is_active');
        });

        DB::table('payment_methods')->where('code', 'cod')->update(['on_delivery_only' => true]);

        // Retire the old blurb, which now contradicts the flag — but only where
        // it is still the text we shipped, so a rewritten one is left alone.
        DB::table('payment_methods')
            ->where('code', 'cod')
            ->where('instructions', 'Pay the full amount in cash when the goat is delivered. Please keep exact change ready.')
            ->update([
                'instructions' => 'Settle the remaining balance in cash when your goat is delivered. '
                    .'Please keep the exact change ready.',
            ]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('on_delivery_only');
        });
    }
};
