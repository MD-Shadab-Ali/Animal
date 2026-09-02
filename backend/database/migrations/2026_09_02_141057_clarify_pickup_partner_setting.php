<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Say plainly what the two collection fields are for.
 *
 * "Places to stay nearby" is what I called the hotel list, on a tab that had
 * no icon and sat last in the row. The farm went looking for somewhere to put
 * hotels, did not find it, and reasonably concluded the feature was broken.
 * The list was simply empty, and an empty list shows nothing -- correctly, but
 * indistinguishably from being absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'pickup_partners')->update([
            'label' => 'Hotels and guest houses nearby',
            // varchar(255), so this has to earn every character: the format,
            // an example of it, and what happens when it is left empty.
            'hint' => 'One per line: Name | Phone | Note. Example: '
                .'Hill View Lodge | 01-4000000 | ten minutes on foot. '
                .'Shown to buyers collecting in person. Recommendations only -- no room is '
                .'booked here. Empty means nothing is shown.',
            'updated_at' => now(),
        ]);

        DB::table('settings')->where('key', 'pickup_instructions')->update([
            'label' => 'How to find the farm',
            'hint' => 'Shown to anyone who books a collection. Landmarks beat street names.',
            'updated_at' => now(),
        ]);

        Setting::flushCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'pickup_partners')->update([
            'label' => 'Places to stay nearby',
            'updated_at' => now(),
        ]);

        Setting::flushCache();
    }
};
