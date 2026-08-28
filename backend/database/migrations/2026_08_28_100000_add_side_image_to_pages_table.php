<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture beside the words on a CMS page.
 *
 * Separate from `banner_image`, which runs across the top: this one sits in
 * the column next to the copy, where an About page can show the farm it is
 * describing. Optional on every page -- a page without one keeps the single
 * reading column it has now, rather than leaving a hole where a photo would be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'side_image')) {
                $table->string('side_image')->nullable()->after('banner_image');
            }

            // Shown under the picture. Also the alt text, so a page that
            // describes its own photograph is readable without seeing it.
            if (! Schema::hasColumn('pages', 'side_image_caption')) {
                $table->string('side_image_caption')->nullable()->after('side_image');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['side_image', 'side_image_caption'],
                fn (string $column) => Schema::hasColumn('pages', $column)
            ));

            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }
};
