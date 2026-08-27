<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photo of the animal, attached to the moment the order moved.
 *
 * "Preparing" is the point where a buyer stops being able to see what they
 * bought: the listing photo was taken before they ordered, and for a listing
 * sold across a range it may not even be the animal they are getting. A photo
 * taken at the moment staff move the order is the only proof of the actual
 * animal, so it is stored against the status change rather than the order --
 * one order can move several times, and each move has its own evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('order_status_histories', 'photo')) {
                $table->string('photo')->nullable()->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            if (Schema::hasColumn('order_status_histories', 'photo')) {
                $table->dropColumn('photo');
            }
        });
    }
};
