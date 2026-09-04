<?php

namespace App\Services;

use App\Models\Goat;
use App\Models\GoatWeight;
use RuntimeException;
use ZipArchive;

/**
 * Every animal's tag for one listing, in one file.
 *
 * Printing ear tags is a batch job -- a sheet of them for a pen -- so the
 * useful unit is the whole listing, not one code at a time. Doing it one at a
 * time is how a goat ends up with no tag on its pen.
 */
class GoatQrArchive
{
    /**
     * Write these animals' codes to a zip and say where it is.
     *
     * The caller deletes the file: it exists for the length of one download
     * and nothing about it is worth keeping afterwards, since every code can
     * be drawn again from the token whenever it is asked for.
     *
     * @param  iterable<GoatWeight>  $weights
     */
    public function write(iterable $weights): string
    {
        $path = tempnam(sys_get_temp_dir(), 'goat-qr-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the QR archive.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Could not open the QR archive for writing.');
        }

        $used = [];
        $count = 0;

        foreach ($weights as $weight) {
            $zip->addFromString($this->uniqueName($weight, $used), $weight->qrJpeg(512));
            $count++;
        }

        /*
         * An archive with nothing in it is not written to disk at all -- libzip
         * removes the file rather than leaving an empty one -- so the download
         * that followed would stream a file that is no longer there. Refusing
         * here turns that into something the caller can see.
         */
        if ($count === 0) {
            $zip->close();
            @unlink($path);

            throw new RuntimeException('There are no animals on this listing to make QR codes for.');
        }

        if (! $zip->close()) {
            @unlink($path);

            throw new RuntimeException('Could not finish writing the QR archive.');
        }

        return $path;
    }

    /** What the archive is called once it lands in somebody's downloads. */
    public function fileName(Goat $goat): string
    {
        return GoatWeight::fileNamePart($goat->name).'-QR-Codes.zip';
    }

    /**
     * Two goats of the same weight would otherwise be one file.
     *
     * A zip quietly keeps both entries under one name and most unzip tools
     * show only the last, so a pen of identical twins would print one tag. The
     * ear tag is what tells them apart on the farm, so it is what tells them
     * apart here; an animal with no tag falls back to a counter.
     *
     * @param  array<string, int>  $used
     */
    private function uniqueName(GoatWeight $weight, array &$used): string
    {
        $name = $weight->qrFileName();

        if (! isset($used[$name])) {
            $used[$name] = 1;

            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = substr($name, 0, -(strlen($extension) + 1));
        $suffix = filled($weight->tag)
            ? GoatWeight::fileNamePart($weight->tag)
            : (string) (++$used[$name]);

        $candidate = $base.'-'.$suffix.'.'.$extension;

        while (isset($used[$candidate])) {
            $candidate = $base.'-'.$suffix.'-'.(++$used[$name]).'.'.$extension;
        }

        $used[$candidate] = 1;

        return $candidate;
    }
}
