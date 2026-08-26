<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a buyer ask for a heavier animal than the one listed, and pay for it.
 *
 * There is no separate "sold by the kilo" mode. Every listing already carries
 * a weight and an asking price, and those two are the rate: 21,000 at 18 kg is
 * 1,166.67 a kilo whether anyone says so or not. All a seller adds is the
 * heaviest they can supply, and the listing starts offering weights in between.
 *
 * Leave that blank and the listing behaves exactly as it always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goats', function (Blueprint $table) {
            // Worked out from price / weight_kg on save, never typed in. Stored
            // rather than computed on read so orders can snapshot it and so the
            // shop can sort and filter on it.
            if (! Schema::hasColumn('goats', 'price_per_kg')) {
                $table->decimal('price_per_kg', 12, 2)->nullable()->after('sale_price');
            }

            // The range the seller will supply between. Both blank means "just
            // this one animal"; either one falls back to the advertised weight.
            if (! Schema::hasColumn('goats', 'min_weight_kg')) {
                $table->decimal('min_weight_kg', 8, 2)->nullable()->after('price_per_kg');
            }
            if (! Schema::hasColumn('goats', 'max_weight_kg')) {
                $table->decimal('max_weight_kg', 8, 2)->nullable()->after('min_weight_kg');
            }

            // The increment the buyer's selector moves in.
            if (! Schema::hasColumn('goats', 'weight_step_kg')) {
                $table->decimal('weight_step_kg', 8, 2)->default(1)->after('max_weight_kg');
            }
        });

        if (! Schema::hasColumn('cart_items', 'weight_kg')) {
            Schema::table('cart_items', function (Blueprint $table) {
                // 0 means "no weight was chosen", which keeps the unique index
                // below working: a NULL would let one listing be added twice.
                $table->decimal('weight_kg', 8, 2)->default(0)->after('goat_id');
            });
        }

        // Two weights of the same listing are two different cart lines.
        //
        // Added before the old one is dropped, and deliberately leading with
        // cart_id: MySQL refuses to drop an index a foreign key is relying on,
        // so the replacement has to be able to cover that key first.
        if (! $this->hasIndex('cart_items', 'cart_items_cart_goat_weight_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unique(['cart_id', 'goat_id', 'weight_kg'], 'cart_items_cart_goat_weight_unique');
            });
        }

        if ($this->hasIndex('cart_items', 'cart_items_cart_id_goat_id_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropUnique('cart_items_cart_id_goat_id_unique');
            });
        }

        Schema::table('order_items', function (Blueprint $table) {
            // Snapshotted like every other figure on the line, so a later change
            // to the listing's price never rewrites what someone already paid.
            if (! Schema::hasColumn('order_items', 'weight_kg')) {
                $table->decimal('weight_kg', 8, 2)->nullable()->after('goat_thumbnail');
            }
            if (! Schema::hasColumn('order_items', 'price_per_kg')) {
                $table->decimal('price_per_kg', 12, 2)->nullable()->after('weight_kg');
            }
        });
    }

    public function down(): void
    {
        // Each step is guarded the same way `up()` is: a migration that fails
        // half way through leaves DDL behind, and a `down()` that assumes a
        // clean slate then cannot undo what did land.
        Schema::table('order_items', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['weight_kg', 'price_per_kg'],
                fn (string $column) => Schema::hasColumn('order_items', $column)
            ));

            if ($drop) {
                $table->dropColumn($drop);
            }
        });

        if (! $this->hasIndex('cart_items', 'cart_items_cart_id_goat_id_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unique(['cart_id', 'goat_id'], 'cart_items_cart_id_goat_id_unique');
            });
        }

        if ($this->hasIndex('cart_items', 'cart_items_cart_goat_weight_unique')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropUnique('cart_items_cart_goat_weight_unique');
            });
        }

        if (Schema::hasColumn('cart_items', 'weight_kg')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropColumn('weight_kg');
            });
        }

        Schema::table('goats', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['price_per_kg', 'min_weight_kg', 'max_weight_kg', 'weight_step_kg'],
                fn (string $column) => Schema::hasColumn('goats', $column)
            ));

            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }

    /** Index names are not portable, so they are looked up rather than assumed. */
    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
