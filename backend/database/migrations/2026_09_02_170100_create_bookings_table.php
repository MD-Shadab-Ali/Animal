<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A room, held for somebody, for a run of nights.
 *
 * Its own table rather than an order, because an order is a list of animals
 * going out on a van: it carries weights, a delivery zone, a seller per line
 * and a stock count, and a room has none of those. What it has instead is two
 * dates, and everything difficult about this feature follows from them.
 *
 * The whole of that difficulty is the third table below. Read that comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Guest snapshot — kept even if the account is later edited, for
            // the same reason the order carries the address it was placed with.
            $table->string('guest_name');
            $table->string('guest_phone', 30);
            $table->string('guest_email')->nullable();
            $table->text('guest_notes')->nullable();

            /*
             * The stay itself.
             *
             * Half-open: the guest sleeps on check_in and leaves on check_out,
             * so a booking occupies the nights [check_in, check_out) and never
             * the checkout date. That is the one rule the whole calendar rests
             * on -- get it wrong by a day and two guests who never overlap are
             * turned away, or two who do overlap are both let in.
             */
            $table->date('check_in');
            $table->date('check_out');

            /*
             * Stored rather than re-derived from the dates on every read.
             *
             * The money is per night and was agreed at this count. A booking
             * whose dates staff later shift by a day must not silently re-price
             * itself against a total the guest never saw.
             */
            $table->unsignedSmallInteger('nights');
            $table->unsignedTinyInteger('guests')->default(1);

            // Room snapshot at time of booking, for the same reason order lines
            // snapshot the goat: a room can be renamed or re-rated, and a stay
            // from last Dashain still has to say what was agreed.
            $table->string('room_name');
            $table->string('room_thumbnail')->nullable();
            $table->decimal('rate_per_night', 12, 2);

            // Money
            $table->decimal('room_charge', 12, 2)->default(0);
            $table->decimal('extra_guest_charge', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 10)->default('NPR');

            /*
             * Payment, in the same columns and the same words the order uses,
             * because the same PaymentService writes both.
             *
             * With one plan missing, and its absence is the point: there is no
             * `on_delivery`. Cash on delivery is a rider taking money at a door
             * for an animal somebody can see; nobody is at a door here, and a
             * room held for a guest who never arrives and never paid is a night
             * the farm could not sell to anybody else.
             */
            $table->string('payment_method');
            $table->enum('payment_plan', ['full', 'advance'])->default('full');
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid', 'refunded'])->default('unpaid');
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('advance_required', 12, 2)->nullable();
            $table->string('transaction_id')->nullable();

            // Where the stay has got to. Mirrors the order's flow in shape:
            // forward-only, with cancelled sitting outside it.
            $table->enum('status', [
                'placed', 'confirmed', 'checked_in', 'checked_out', 'cancelled',
            ])->default('placed');
            $table->text('admin_note')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'check_in']);
            $table->index(['user_id', 'status']);
            $table->index(['room_id', 'check_in']);
        });

        /*
         * A stay that ends before it starts is not a stay.
         *
         * At the database rather than in a validation rule, because `nights` is
         * derived from this pair and a zero-night booking would sail through
         * pricing as a free room. Nothing in the application is permitted to
         * write one, and now nothing can.
         */
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_dates_ordered CHECK (check_out > check_in)');

        /*
        |----------------------------------------------------------------------
        | The one table that makes double-booking impossible
        |----------------------------------------------------------------------
        |
        | One row per night a room is actually occupied, with a unique index on
        | (room_id, night). Booking a room writes its nights here in the same
        | transaction that writes the booking, so the second of two guests
        | reaching for the same night gets a duplicate-key error and the whole
        | booking rolls back.
        |
        | Why the database has to be the one saying no:
        |
        | Checking for an overlap in PHP -- select the bookings of this room
        | where check_in < requested_out and check_out > requested_in -- is
        | correct and useless on its own. Two requests can run that select at
        | the same instant, both see an empty result, and both insert. Nothing
        | in the query prevents it, because the row that would have conflicted
        | did not exist yet when either of them looked. That is not an exotic
        | race: it is one Dashain weekend and two people on the last room.
        |
        | Why a row per night rather than a range constraint: MySQL has no
        | exclusion constraint. Postgres could refuse an overlapping daterange
        | outright with a GiST index; MySQL 8 cannot express that at all. The
        | only thing it can refuse is a duplicate value in a unique index, so
        | the range has to be materialised down to something duplicable -- and
        | the natural grain of a room is the night.
        |
        | It pays for itself twice over. "Which nights are taken" -- the query
        | behind the date picker -- becomes an indexed read of a date column
        | rather than range arithmetic across every booking that room ever had,
        | and a cancelled booking releases its nights by deleting rows rather
        | than by every availability query having to remember to exclude it.
        |
        | The cost is one row per room per night: a room booked solid for a
        | year is 365 rows.
        |
        */
        Schema::create('booking_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('night');

            // The whole point of the table.
            $table->unique(['room_id', 'night']);

            // No timestamps: this is an index of what is occupied, not a record
            // of anything. When it was written is the booking's business.
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_nights');
        Schema::dropIfExists('bookings');
    }
};
