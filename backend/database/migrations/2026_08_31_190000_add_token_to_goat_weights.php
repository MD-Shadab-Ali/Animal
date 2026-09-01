<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A public name for an animal that is not its row number.
 *
 * The tag on a goat's ear is meant to be scanned by whoever is holding the
 * goat -- a buyer at the gate checking they are being handed the animal they
 * paid for. That means the address has to work without signing in, and an
 * address built from the row id would let anyone read the whole pen by
 * counting upwards. This is the identifier that goes in the QR code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goat_weights', function (Blueprint $table) {
            $table->string('token', 32)->nullable()->after('id');
        });

        // Animals recorded before this existed still need to be scannable.
        DB::table('goat_weights')->whereNull('token')->orderBy('id')
            ->each(fn ($row) => DB::table('goat_weights')
                ->where('id', $row->id)
                ->update(['token' => Str::random(32)]));

        Schema::table('goat_weights', function (Blueprint $table) {
            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::table('goat_weights', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }
};
