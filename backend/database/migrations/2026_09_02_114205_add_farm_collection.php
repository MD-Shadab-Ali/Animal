<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collecting the goat from the farm, at a time the buyer picked.
 *
 * Everything until now has been delivery: three zones, a charge, and a van.
 * Buyers who would rather come and take the animal themselves had no way to
 * say so, and the ones who turned up anyway arrived whenever they arrived --
 * which is how somebody ends up at a farm gate at dusk with a goat and no way
 * home. A booked slot prevents that rather than treating it afterwards.
 *
 * Modelled as a delivery zone because that is what the buyer is choosing
 * between: how the goat gets to them. Making it a separate concept would have
 * meant a second answer to a question the checkout already asks once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->boolean('is_pickup')->default(false)->after('description');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Null means delivery, which is every order placed so far. It is
            // the durable signal too: a zone can be renamed or deleted years
            // from now and this still says the buyer came and fetched it.
            $table->dateTime('pickup_at')->nullable()->after('delivery_estimate');
        });

        /*
         * Only for an installation that already has zones -- an upgrade of a
         * running shop, which is the case this migration exists for.
         *
         * On an empty table the seeder is about to run and will create this
         * with the delivery zones, in order. Inserting here as well would put
         * collection first in a table where "the first active zone" is what
         * every default and every fixture reaches for, quietly turning ordinary
         * deliveries into appointments at a farm gate.
         */
        $hasZones = DB::table('delivery_zones')->exists();
        $hasPickup = DB::table('delivery_zones')->where('is_pickup', true)->exists();

        if ($hasZones && ! $hasPickup) {
            DB::table('delivery_zones')->insert([
                'name' => 'Collect from the farm',
                'description' => 'Come to us and take the goat yourself. Pick a time below '
                    .'and we will have it ready and waiting.',
                'is_pickup' => true,
                'charge' => 0,
                'free_above' => null,
                'estimated_time' => 'Ready at the time you choose',
                'is_active' => true,
                // Last in the list: delivery is what most buyers want, and this
                // is the deliberate choice rather than the default one.
                'sort_order' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * Settings rather than constants, because the farm decides these, not
         * the code. The admin page builds its own fields from these rows, so a
         * new "Pickup" tab appears with no screen to write.
         */
        $settings = [
            ['pickup_opens_at', '07:00', 'text', 'Earliest collection time',
                'First slot of the day, as 24-hour time.'],
            ['pickup_closes_at', '18:00', 'text', 'Latest collection time',
                'Last slot of the day. Leave enough daylight for the buyer to get home.'],
            ['pickup_lead_days', '1', 'number', 'Days of notice needed',
                'How far ahead the earliest bookable slot is. 1 means from tomorrow.'],
            ['pickup_instructions', '', 'textarea', 'How to find us',
                'Shown to anyone who books a collection. Landmarks beat street names.'],
            ['pickup_partners', '', 'textarea', 'Places to stay nearby',
                'One per line as Name | Phone | Note. Shown to buyers collecting in '
                    .'person, for anyone travelling too far to get back the same day. '
                    .'Recommendations only -- we take no booking and no money.'],
        ];

        foreach ($settings as $index => [$key, $value, $type, $label, $hint]) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'group' => 'pickup',
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'label' => $label,
                'hint' => $hint,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The rows above were written with the query builder, which fires no
        // model events -- so the cached settings map still predates them.
        Setting::flushCache();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'pickup_opens_at', 'pickup_closes_at', 'pickup_lead_days',
            'pickup_instructions', 'pickup_partners',
        ])->delete();

        DB::table('delivery_zones')->where('is_pickup', true)->delete();

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('pickup_at'));
        Schema::table('delivery_zones', fn (Blueprint $table) => $table->dropColumn('is_pickup'));

        Setting::flushCache();
    }
};
