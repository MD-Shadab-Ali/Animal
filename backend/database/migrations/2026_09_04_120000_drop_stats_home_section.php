<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the "Our numbers" block from the homepage.
 *
 * The seeder no longer creates it, but a seeder only runs on a fresh install --
 * the farm's database already has the row, and the storefront no longer has a
 * component for the type. Left alone it would render as nothing while still
 * sitting in the admin's section list, editable and with no visible effect.
 *
 * Deleted by type, which is unique on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('home_sections')->where('type', 'stats')->delete();
    }

    public function down(): void
    {
        // The block itself is gone from the storefront, so there is nothing to
        // restore it to. Recreating the row would only put a dead entry back in
        // the admin list.
    }
};
