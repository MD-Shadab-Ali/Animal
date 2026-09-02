<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Places to stay, as records rather than a line of text.
 *
 * They began as one setting holding "Name | Phone | Note" per line, which was
 * the right size for what it was then: a list. Asking it to carry a photograph
 * of the rooms, a nightly rate and a website is asking a textarea to be a
 * table, so it becomes one.
 *
 * What this is not is a booking system. The buyer rings the hotel; no room is
 * held here and no money for one passes through the shop. Every column below
 * exists to help somebody decide and then get in touch -- there is nothing to
 * reserve, and deliberately so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image')->nullable();
            $table->text('description')->nullable();

            // Every one of these is a way to reach them. A place with none is
            // a photograph the buyer can do nothing with.
            $table->string('phone', 40)->nullable();
            $table->string('website_url')->nullable();
            $table->string('map_url')->nullable();

            // Shown as "from", because a nightly rate is a starting point and
            // the hotel is the one who sets it.
            $table->decimal('price_from', 10, 2)->nullable();
            $table->string('distance_note')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
         * Carry across whatever the farm already typed into the setting, so an
         * upgrade does not quietly empty a list somebody had filled in.
         */
        $raw = trim((string) DB::table('settings')->where('key', 'pickup_partners')->value('value'));

        if ($raw !== '') {
            $order = 0;

            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $parts = array_map('trim', explode('|', trim($line)));

                if (($parts[0] ?? '') === '') {
                    continue;
                }

                DB::table('stay_partners')->insert([
                    'name' => $parts[0],
                    'phone' => ($parts[1] ?? '') !== '' ? $parts[1] : null,
                    'description' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
                    'is_active' => true,
                    'sort_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // One source of truth. Leaving the setting behind would mean two places
        // to edit the same list and no way to tell which one is shown.
        DB::table('settings')->where('key', 'pickup_partners')->delete();

        Setting::flushCache();
    }

    public function down(): void
    {
        $lines = DB::table('stay_partners')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (object $p) => trim(implode(' | ', array_filter([
                $p->name, $p->phone, $p->description,
            ]))))
            ->implode("\n");

        DB::table('settings')->insert([
            'group' => 'pickup',
            'key' => 'pickup_partners',
            'value' => $lines,
            'type' => 'textarea',
            'label' => 'Hotels and guest houses nearby',
            'hint' => 'One per line: Name | Phone | Note.',
            'sort_order' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('stay_partners');

        Setting::flushCache();
    }
};
