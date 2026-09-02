<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recommended guest houses go, because the farm has rooms of its own now.
 *
 * That table existed to answer one question -- where can a buyer collecting a
 * goat sleep tonight -- at a time when the only honest answer was somebody
 * else's guest house and a phone number. Its own migration said so plainly:
 * "What this is not is a booking system."
 *
 * `rooms` answers the same question and can hold the bed. Keeping both would
 * put two lists of places to stay in the admin, one of which cannot be booked,
 * with nothing on the outside of either to say which is which.
 *
 * The two migrations that built it are left where they are on purpose. A
 * database that already ran them has to be able to move forward from here, and
 * rewriting history to pretend the feature never shipped would strand it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('stay_partners');
    }

    /**
     * Puts the table back, empty.
     *
     * The rows cannot come back -- they were somebody's typed-in list of local
     * hotels, and nothing else in the schema held a copy. Reversing this
     * restores the shape so the migrations either side of it still run; it does
     * not restore the guest houses.
     */
    public function down(): void
    {
        Schema::create('stay_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website_url')->nullable();
            $table->string('map_url')->nullable();
            $table->decimal('price_from', 10, 2)->nullable();
            $table->string('distance_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
};
