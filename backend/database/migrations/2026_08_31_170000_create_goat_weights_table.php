<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actual animals behind a listing.
 *
 * Until now a weight-priced listing described a range and nothing more: a
 * buyer sliding to 45 kg was making a request, priced by arithmetic, and the
 * real weight was not known until somebody put the goat on a scale at the
 * door. Each row here is one real animal with one real weight, so the slider
 * can offer what actually exists instead of what could be calculated.
 *
 * A listing with no rows here keeps the old behaviour untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goat_weights', function (Blueprint $table) {
            $table->id();

            $table->foreignId('goat_id')->constrained()->cascadeOnDelete();

            $table->decimal('weight_kg', 8, 2);

            // An ear tag or pen number, so staff can tell two 47 kg goats apart
            // when one of them is the one that was sold.
            $table->string('tag')->nullable();

            // One animal, one sale. 'sold' is what takes it off the slider.
            $table->enum('status', ['available', 'sold'])->default('available');
            $table->timestamp('sold_at')->nullable();

            $table->timestamps();

            // Every lookup is "the available ones for this listing, by weight".
            $table->index(['goat_id', 'status', 'weight_kg'], 'goat_weights_pool_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goat_weights');
    }
};
