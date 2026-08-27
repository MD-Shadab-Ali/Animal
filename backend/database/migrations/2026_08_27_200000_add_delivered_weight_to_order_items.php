<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the goat actually weighed when it got there.
 *
 * A live animal does not arrive at the weight it left at: it loses gut fill
 * and water on the road, and the scale at the far end is not the scale it was
 * weighed on. So `weight_kg` is what was ordered and paid for, and this is
 * what turned up -- two different facts that both have to survive, because
 * overwriting the first would erase what the buyer agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'delivered_weight_kg')) {
                $table->decimal('delivered_weight_kg', 8, 2)->nullable()->after('weight_kg');
            }

            // Who recorded it and when. A weight that turns into an argument
            // later is only worth having if it says where it came from.
            if (! Schema::hasColumn('order_items', 'weighed_at')) {
                $table->timestamp('weighed_at')->nullable()->after('delivered_weight_kg');
            }
            if (! Schema::hasColumn('order_items', 'weighed_by')) {
                $table->foreignId('weighed_by')->nullable()->after('weighed_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'weighed_by')) {
                $table->dropConstrainedForeignId('weighed_by');
            }

            $drop = array_values(array_filter(
                ['delivered_weight_kg', 'weighed_at'],
                fn (string $column) => Schema::hasColumn('order_items', $column)
            ));

            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }
};
