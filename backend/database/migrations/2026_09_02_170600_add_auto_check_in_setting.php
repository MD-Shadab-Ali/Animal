<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let the farm decide whether settling up checks a guest in.
 *
 * The order side already has this switch under `auto_deliver_on_payment`: cash
 * handed to a rider closes the order, because that money could only have been
 * paid at the door. A room balance is the same promise -- the advance plan says
 * "the rest on arrival" -- so paying it means the guest is standing there.
 *
 * With one difference that matters, and which the code enforces rather than
 * this setting: a room balance *can* be paid from a sofa three months early,
 * and a booking that flipped to checked-in on that would put a phantom guest in
 * the farm's "in the house now" list. Arrival has to have actually come round.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'group'      => 'homestay',
            'key'        => 'auto_check_in_on_payment',
            'value'      => '1',
            'type'       => 'boolean',
            'label'      => 'Settling up checks the guest in',
            'hint'       => 'On the day they arrive, paying the balance moves the booking to '
                .'Checked in by itself. Off means somebody at the desk does it. Paying early '
                .'never checks anybody in, either way.',
            'sort_order' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Written with the query builder, which fires no model events, so the
        // cached settings map would never hear about this one.
        Setting::flushCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'auto_check_in_on_payment')->delete();

        Setting::flushCache();
    }
};
