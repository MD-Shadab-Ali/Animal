<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The things about the homestay that only the farm can know.
 *
 * None of these are decisions for a developer. What time a room is ready, how
 * long before a stay somebody may book it, and what the house asks of guests
 * are all answers that change with the season and the staff -- so they are
 * settings, in a group that becomes its own tab, the same as every other part
 * of this shop the farm runs itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            [
                'group'      => 'homestay',
                'key'        => 'homestay_enabled',
                'value'      => '1',
                'type'       => 'boolean',
                'label'      => 'Offer rooms on the site',
                'hint'       => 'Off hides the Homestay pages and stops new bookings. Stays already '
                    .'booked are unaffected -- staff still see them in the admin.',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'homestay_intro',
                'value'      => 'Stay the night at the farm. Simple rooms, a hot meal, '
                    .'and the animals a short walk away.',
                'type'       => 'textarea',
                'label'      => 'Introduction on the rooms page',
                'hint'       => 'One or two sentences under the heading. Empty means nothing is shown.',
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'checkin_time',
                'value'      => '14:00',
                'type'       => 'text',
                'label'      => 'Check-in from',
                'hint'       => '24-hour, as 14:00. Shown on the room page and on the booking.',
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'checkout_time',
                'value'      => '11:00',
                'type'       => 'text',
                'label'      => 'Check-out by',
                'hint'       => '24-hour, as 11:00. The room is free again from this time, which is '
                    .'why the departure day is never charged as a night.',
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'booking_lead_days',
                'value'      => '1',
                'type'       => 'number',
                'label'      => 'Days of notice needed',
                'hint'       => 'How far ahead the earliest bookable night is. 0 lets somebody book '
                    .'tonight; 1 means the room has to be made up first.',
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'booking_horizon_days',
                'value'      => '180',
                'type'       => 'number',
                'label'      => 'How far ahead rooms can be booked',
                'hint'       => 'In days. Beyond this the calendar stops, because a rate agreed a '
                    .'year out is a guess the farm would still have to honour.',
                'sort_order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'homestay_house_rules',
                'value'      => '',
                'type'       => 'textarea',
                'label'      => 'House rules',
                'hint'       => 'Shown on every room page and on the booking. Quiet hours, shoes, '
                    .'meal times. Empty means nothing is shown.',
                'sort_order' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'group'      => 'homestay',
                'key'        => 'homestay_cancellation_note',
                'value'      => 'Tell us at least 48 hours before you arrive and we will refund '
                    .'what you have paid.',
                'type'       => 'textarea',
                'label'      => 'What happens if a guest cancels',
                'hint'       => 'Shown before the guest pays and on their booking afterwards. This is '
                    .'the promise the farm is making, so say only what it will keep.',
                'sort_order' => 8,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Inserted with the query builder, which fires no model events -- so
        // the cached map would never have heard of any of these and every one
        // would silently read as its code default.
        Setting::flushCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'homestay')->delete();

        Setting::flushCache();
    }
};
