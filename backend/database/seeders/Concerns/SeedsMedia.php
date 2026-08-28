<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Puts the images bundled with the seeders onto the public disk.
 *
 * The files live in database/seeders/assets so they travel with the repo --
 * storage/app/public is generated, and a fresh clone would otherwise seed a
 * catalogue of blank cards. Everything the seeder writes goes under a seed/
 * prefix, which keeps it clear of anything an admin uploads through Filament.
 */
trait SeedsMedia
{
    /**
     * Copy a bundled asset onto the public disk and hand back the path to
     * store on the model, or null when the file is not there.
     *
     * Returning null rather than throwing is deliberate: a missing decorative
     * image is not a reason for a seed run to fail halfway and leave the
     * database half-built.
     */
    protected function seedImage(string $relative): ?string
    {
        $source = database_path('seeders/assets/'.$relative);

        if (! is_file($source)) {
            $this->command?->warn("Seed image missing, skipped: {$relative}");

            return null;
        }

        $target = 'seed/'.$relative;
        $disk = Storage::disk('public');

        // Copied once. Re-seeding is common during development and there is no
        // reason to rewrite bytes that are already identical.
        if (! $disk->exists($target)) {
            $disk->put($target, file_get_contents($source));
        }

        return $target;
    }
}
