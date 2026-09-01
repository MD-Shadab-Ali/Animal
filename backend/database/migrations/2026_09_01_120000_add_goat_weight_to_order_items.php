<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which actual animal this line is being filled with.
 *
 * `weight_kg` on this row is what the buyer asked for and paid for; it stays
 * exactly as it was. This says which goat was walked out of the pen to satisfy
 * it, chosen by staff at the Preparing step. The two can differ -- a buyer who
 * asked for 21 kg is given the 20 kg or the 22.86 kg animal, whichever is
 * nearer -- and settling that difference remains the delivery weigh-in's job.
 *
 * Null on delete rather than cascade: removing an animal from the farm records
 * must never quietly erase which animal an old order was filled with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('goat_weight_id')
                ->nullable()
                ->after('goat_id')
                ->constrained('goat_weights')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goat_weight_id');
        });
    }
};
