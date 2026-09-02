<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The farm's own rooms.
 *
 * This is the thing the removed stay_partners table deliberately was not. That
 * one held somebody else's guest house and could promise nothing, so it carried
 * a phone number and stopped. These rooms are ours: the farm sets the rate, the
 * farm knows which nights are free, and the farm is the one that has to honour
 * a booking -- so a room can be priced, held and paid for here.
 *
 * Shaped after `goats` on purpose rather than by accident of copying: a room is
 * the other thing this shop sells. Same slug, same status enum, same featured
 * flag, sort order and SEO pair, so the listing page, the detail page and the
 * admin table are variations on screens that already exist rather than a second
 * vocabulary for the same ideas.
 *
 * What it does not share is stock. A goat has a number of animals; a room has a
 * calendar, and one room is free or taken depending entirely on which nights
 * are being asked about. That lives in `booking_nights`, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->unique();
            $table->string('thumbnail')->nullable();

            // What the room is
            $table->string('room_type')->nullable();
            $table->unsignedTinyInteger('max_guests')->default(2);

            /*
             * How many people the nightly rate already covers.
             *
             * Separate from max_guests because the two answer different
             * questions: one is what the room can hold, the other is what the
             * price buys. A double let to three people is legitimate and costs
             * more, and without this the shop has no way to say so.
             */
            $table->unsignedTinyInteger('base_guests')->default(2);
            $table->unsignedTinyInteger('beds')->default(1);
            $table->boolean('has_private_bathroom')->default(true);
            $table->json('amenities')->nullable(); // admin-defined label/value rows

            // Money
            $table->decimal('price_per_night', 12, 2);
            $table->decimal('extra_guest_fee', 12, 2)->nullable();

            /*
             * The shape of a stay this room will take.
             *
             * Per room rather than per site: a family room is worth blocking
             * out for two nights at a time, a single is not, and the farm is
             * the one who knows which. Enforced on the server, because the date
             * picker in the browser is where a guest chooses and not where the
             * farm has to keep a bed free.
             */
            $table->unsignedTinyInteger('min_nights')->default(1);
            $table->unsignedSmallInteger('max_nights')->default(14);

            // Content
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            // Flags
            $table->enum('status', ['draft', 'published', 'archived'])->default('published');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_featured']);
            $table->index('price_per_night');
        });

        Schema::create('room_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['room_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_images');
        Schema::dropIfExists('rooms');
    }
};
