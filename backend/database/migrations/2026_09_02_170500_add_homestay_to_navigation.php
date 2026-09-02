<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the rooms in the main navigation.
 *
 * The seeder gains the same entry, but a seeder only runs on a fresh install --
 * and the farm this is being built for already has its menu, edited by hand.
 * Without this the feature would ship complete and unreachable: every page
 * built, and no way to arrive at one.
 *
 * The row is inserted rather than the menu rebuilt, because the header is
 * admin-editable and somebody may have reordered or renamed things. Nothing
 * here touches an item it did not add.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'header')->value('id');

        // No header menu means a database that has not been seeded yet. The
        // seeder will carry the link when it runs.
        if (! $menuId) {
            return;
        }

        /*
         * After Categories, which is where somebody already looking for another
         * thing the farm offers will be. The end of the row, past Contact, is
         * where a navigation item goes to be ignored.
         */
        $after = DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->where('label', 'Categories')
            ->value('sort_order');

        $position = $after === null ? 99 : $after + 1;

        /*
         * Everything at or past that slot shuffles down first. Sharing a
         * sort_order with the item already there would leave the two ordered
         * arbitrarily -- which reads as a menu that rearranges itself between
         * one machine and the next.
         */
        DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->where('sort_order', '>=', $position)
            ->increment('sort_order');

        DB::table('menu_items')->updateOrInsert(
            ['menu_id' => $menuId, 'label' => 'Homestay'],
            [
                'url'        => '/homestay',
                'is_active'  => true,
                'sort_order' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'header')->value('id');

        if (! $menuId) {
            return;
        }

        // Only the row this migration added. The gap it leaves in the sort
        // order is harmless: the values order the menu, they do not number it.
        DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->where('label', 'Homestay')
            ->delete();
    }
};
