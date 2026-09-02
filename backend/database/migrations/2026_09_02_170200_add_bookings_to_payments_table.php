<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the money ledger carry a room as well as an order.
 *
 * There was a cheaper-looking option here -- a `booking_payments` table -- and
 * it is wrong for one reason worth writing down. This ledger is not a detail of
 * how orders work; it is the answer to "what has this shop taken, from whom,
 * through what". A second table means that question needs a UNION to answer,
 * two Filament resources showing halves of the same book, and two places where
 * an advance is worked out, confirmed, refunded and reconciled against a
 * gateway. Every one of those is somewhere the two copies can drift.
 *
 * So the ledger keeps one row shape and gains a second thing a row can be
 * about. `order_id` becomes nullable, `booking_id` appears beside it, and a
 * CHECK makes it exactly one of the two -- never both, never neither. A row
 * belonging to nothing is money we cannot attribute, which is worse than money
 * we never recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The foreign key has to come off before the column's nullability can
        // change, and go back on afterwards. Split across three closures
        // because MySQL will not take the drop and the re-add in one statement.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->foreignId('booking_id')->nullable()->after('order_id')
                ->constrained()->cascadeOnDelete();

            // Every screen listing a guest's payments filters on this pair the
            // same way the order screens filter on (order_id, status).
            $table->index(['booking_id', 'status']);
        });

        /*
         * Exactly one subject. `(x IS NULL)` evaluates to 0 or 1 in MySQL, so
         * `<>` between the two is true only when they differ -- which is to say
         * when precisely one of them is filled in.
         *
         * MySQL has enforced CHECK since 8.0.16; before that it parsed them and
         * silently did nothing, so it is worth knowing this one actually bites.
         */
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_one_subject '
            .'CHECK ((order_id IS NULL) <> (booking_id IS NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_one_subject');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropIndex(['booking_id', 'status']);
            $table->dropColumn('booking_id');
        });

        // Any payment against a booking went with the column above, so every
        // row left has an order and the column can be required again.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};
