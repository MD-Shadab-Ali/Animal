<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gave each recommended guest house a slug, so it could have a page of its own.
 *
 * Historical now: the feature is dropped a few migrations later, once the farm
 * had rooms of its own to let. This is kept rather than deleted because a
 * database that already ran it has to be able to move forward from here.
 *
 * It used to call StayPartner::uniqueSlug(), and that is why it has been
 * rewritten. A migration naming an application class is only replayable for as
 * long as that class exists, and this one outlived its model by exactly four
 * files -- every fresh database, and so every test run, stopped dead on "Class
 * App\Models\StayPartner not found". A migration describes what happened to the
 * schema at a moment in the past. It has no business depending on what the
 * application looks like today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stay_partners', function (Blueprint $table) {
            // Nullable to begin with: the rows already here have no slug, and a
            // unique NOT NULL column cannot be added on top of them.
            $table->string('slug')->nullable()->unique()->after('name');
        });

        foreach (DB::table('stay_partners')->whereNull('slug')->get() as $partner) {
            $base = Str::slug((string) $partner->name) ?: 'stay';
            $slug = $base;

            for ($n = 2; DB::table('stay_partners')->where('slug', $slug)->exists(); $n++) {
                $slug = $base.'-'.$n;
            }

            DB::table('stay_partners')->where('id', $partner->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('stay_partners', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
