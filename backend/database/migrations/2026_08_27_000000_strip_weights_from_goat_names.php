<?php

use App\Models\Goat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes the weight back out of every listing name.
 *
 * The seed data named goats "Barbari Buck — 18kg", which read fine while a
 * listing meant one animal at one weight. Now that a listing can be bought
 * anywhere inside a range, that name argues with the buyer's own order — and
 * it is the name snapshotted onto the order line and shown to the seller who
 * has to pick the animal.
 *
 * Going forward `Goat::withoutTrailingWeight()` runs on every save, so this is
 * only about rows written before that existed.
 *
 * Slugs are deliberately left alone: they are in links people already hold,
 * and a slug is not shown next to the weight the way a name is.
 */
return new class extends Migration
{
    public function up(): void
    {
        // withTrashed: a restored listing should not bring the old name back.
        Goat::withTrashed()->select('id', 'name')->chunkById(200, function ($goats) {
            foreach ($goats as $goat) {
                $cleaned = Goat::withoutTrailingWeight($goat->name);

                if ($cleaned !== null && $cleaned !== $goat->name) {
                    // Written straight to the table: this is a rename, and
                    // going through the model would touch updated_at and fire
                    // observers for what is a data correction.
                    DB::table('goats')->where('id', $goat->id)->update(['name' => $cleaned]);
                }
            }
        });
    }

    public function down(): void
    {
        // Not reversible: the weight that was in each name is not recorded
        // anywhere, and every listing still carries it in `weight_kg` anyway.
    }
};
