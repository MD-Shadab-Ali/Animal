<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photograph of the particular animal.
 *
 * The listing's gallery shows what this kind of goat looks like. Once a buyer
 * is being handed one specific animal, the useful picture is of that animal --
 * the 44 kg one they are actually getting, not a representative of its breed.
 *
 * Nullable, because a pen of six is worth listing before it is worth
 * photographing, and a row with no picture simply falls back to the listing's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goat_weights', function (Blueprint $table) {
            $table->string('image')->nullable()->after('tag');
        });
    }

    public function down(): void
    {
        Schema::table('goat_weights', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
