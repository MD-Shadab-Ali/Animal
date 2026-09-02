<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * How far ahead a collection may be booked, decided by the farm.
 *
 * It was a constant of sixty days, which is a number I picked. The first buyer
 * to reach past it chose a date in December, was told to "choose one of the
 * collection times we offer" -- a message about the wrong field entirely -- and
 * had no way of knowing where the edge was or that it could move.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'pickup_horizon_days')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'group' => 'pickup',
            'key' => 'pickup_horizon_days',
            'value' => '60',
            'type' => 'number',
            'label' => 'How far ahead people may book',
            'hint' => 'Days. Raise it before a festival, when buyers plan months out.',
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Setting::flushCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'pickup_horizon_days')->delete();

        Setting::flushCache();
    }
};
