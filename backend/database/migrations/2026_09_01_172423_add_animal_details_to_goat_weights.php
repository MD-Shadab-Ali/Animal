<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The facts that belong to one goat, on the row for that goat.
 *
 * A listing carries a single age, a single tooth count and a single colour,
 * which was fine while a listing was one animal. It is not one animal: behind
 * "Golden Goat" are fifteen goats between 20 and 60 kg, and no single age or
 * dentition is true of all of them -- a 20 kg goat and a 60 kg goat of the same
 * breed are years apart. The listing figure was not entered carelessly; the
 * field simply had no correct value to hold.
 *
 * Teeth make it plain, because in goats dentition is how age is read: 2
 * permanent incisors at 12-18 months, 4 by two years, 8 at a full mouth. Three
 * listings already contradict themselves on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goat_weights', function (Blueprint $table) {
            // Nullable throughout, and deliberately so: an unrecorded age is
            // not an age of zero, and an animal nobody has vaccinated yet is
            // not an animal recorded as unvaccinated. The forms and the public
            // page say "not recorded yet" rather than inventing a reading.
            $table->unsignedSmallInteger('age_months')->nullable()->after('weight_kg');
            $table->unsignedTinyInteger('teeth')->nullable()->after('age_months');
            $table->string('color', 120)->nullable()->after('teeth');
            $table->string('health_status', 160)->nullable()->after('color');
            $table->boolean('is_vaccinated')->nullable()->after('health_status');
            $table->date('dewormed_at')->nullable()->after('is_vaccinated');
            $table->date('vet_checked_at')->nullable()->after('dewormed_at');
            $table->text('notes')->nullable()->after('vet_checked_at');
        });

        /*
         * Only what the listing could honestly have been saying about all of
         * them. Colour, health and vaccination are batch facts on a farm: one
         * breed line, one vaccination round, one inspection. Age, teeth and the
         * treatment dates are readings taken from an individual animal, so
         * copying the listing's onto fifteen goats would not be a backfill --
         * it would be fourteen wrong answers written confidently.
         *
         * Per goat rather than one joined UPDATE so this runs the same on any
         * driver; there are a few dozen listings, not a few million.
         */
        DB::table('goats')
            ->select('id', 'color', 'health_status', 'is_vaccinated')
            ->orderBy('id')
            ->each(function (object $goat) {
                DB::table('goat_weights')
                    ->where('goat_id', $goat->id)
                    ->update([
                        'color' => $goat->color,
                        'health_status' => $goat->health_status,
                        'is_vaccinated' => $goat->is_vaccinated,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('goat_weights', function (Blueprint $table) {
            $table->dropColumn([
                'age_months', 'teeth', 'color', 'health_status',
                'is_vaccinated', 'dewormed_at', 'vet_checked_at', 'notes',
            ]);
        });
    }
};
