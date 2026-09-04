<?php

namespace Tests\Feature;

use App\Filament\Resources\Goats\Pages\EditGoat;
use App\Filament\Resources\Goats\RelationManagers\WeightsRelationManager;
use App\Models\Category;
use App\Models\Goat;
use App\Models\GoatWeight;
use App\Models\User;
use App\Services\GoatQrArchive;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Getting the tags off the screen and onto the pens.
 *
 * A code that can only be read inside an edit form is a code nobody prints.
 * These cover the two ways staff actually want them: one animal's tag while
 * looking at that animal, and the whole pen's tags in one go before a print
 * run -- named so a stack of paper can be matched back to the goats.
 */
class GoatQrDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $pool = [], string $name = 'Black Bengal Buck'): Goat
    {
        $goat = Goat::create([
            'category_id' => Category::create(['name' => 'Pen', 'slug' => 'pen'])->id,
            'name' => $name,
            'breed' => 'Black Bengal',
            'gender' => 'male',
            'price' => 20000,
            'weight_kg' => 20,
            'min_weight_kg' => 10,
            'max_weight_kg' => 40,
            'weight_step_kg' => 2.5,
            'stock' => 9,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        foreach ($pool as $attributes) {
            GoatWeight::create(['goat_id' => $goat->id] + $attributes);
        }

        return $goat->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Farm Admin',
            'email' => 'qr-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function entries(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The archive could not be opened.');

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    /** The listing and the real weight, which is what staff sort a stack by. */
    public function test_a_tag_is_named_after_its_listing_and_weight(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 15, 'tag' => 'BB-01'],
            ['weight_kg' => 17.5, 'tag' => 'BB-02'],
        ]);

        $weights = $goat->weights()->orderBy('weight_kg')->get();

        $this->assertSame('Black-Bengal-Buck-15kg.jpg', $weights[0]->qrFileName());
        // The half kilo survives: 17.5 and 17 are different animals, and
        // "17-5kg" reads like a range rather than a weight.
        $this->assertSame('Black-Bengal-Buck-17.5kg.jpg', $weights[1]->qrFileName());
    }

    /** Whatever an admin typed as a listing name still has to be a filename. */
    public function test_a_listing_name_with_punctuation_still_makes_a_filename(): void
    {
        $goat = $this->listing([['weight_kg' => 30]], name: 'Khari / Local "Cross" Khasi');

        $this->assertSame(
            'Khari-Local-Cross-Khasi-30kg.jpg',
            $goat->weights()->first()->qrFileName()
        );
    }

    /** Every animal on the listing, in one file named after the listing. */
    public function test_the_archive_holds_one_code_per_animal(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 15, 'tag' => 'BB-01'],
            ['weight_kg' => 20, 'tag' => 'BB-03', 'status' => 'sold'],
            ['weight_kg' => 22.5, 'tag' => 'BB-04'],
        ]);

        $archive = app(GoatQrArchive::class);
        $path = $archive->write($goat->weights()->orderBy('weight_kg')->get());

        $this->assertSame('Black-Bengal-Buck-QR-Codes.zip', $archive->fileName($goat));
        $this->assertSame([
            'Black-Bengal-Buck-15kg.jpg',
            'Black-Bengal-Buck-20kg.jpg',
            'Black-Bengal-Buck-22.5kg.jpg',
        ], $this->entries($path));

        @unlink($path);
    }

    /**
     * What is in the file is this animal's own code.
     *
     * Named after the listing, but drawn from the goat's own token: a sheet of
     * tags that all scanned to the same page would be worse than no sheet.
     */
    public function test_each_entry_holds_that_animals_own_code(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 15, 'tag' => 'BB-01'],
            ['weight_kg' => 20, 'tag' => 'BB-03'],
        ]);

        $weights = $goat->weights()->orderBy('weight_kg')->get();
        $path = app(GoatQrArchive::class)->write($weights);

        $zip = new ZipArchive;
        $zip->open($path);
        $light = $zip->getFromName('Black-Bengal-Buck-15kg.jpg');
        $heavy = $zip->getFromName('Black-Bengal-Buck-20kg.jpg');
        $zip->close();
        @unlink($path);

        $this->assertSame($weights[0]->qrJpeg(512), $light);
        $this->assertSame($weights[1]->qrJpeg(512), $heavy);
        $this->assertNotSame($light, $heavy);
    }

    /**
     * The file really is a JPEG, not something merely named like one.
     *
     * A tag is only worth downloading if whatever opens it next -- a label
     * sheet, a phone gallery, a chat app -- recognises it, and the extension
     * is not what those go by.
     */
    public function test_a_downloaded_tag_is_a_real_jpeg(): void
    {
        $goat = $this->listing([['weight_kg' => 15, 'tag' => 'BB-01']]);

        $jpeg = $goat->weights()->first()->qrJpeg(512);
        $size = getimagesizefromstring($jpeg);

        $this->assertNotFalse($size, 'The tag is not an image any decoder recognises.');
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame('image/jpeg', $size['mime']);

        // Square, and drawn on whole modules -- a code stretched or resampled
        // onto a half pixel is one a scanner has to work to read.
        $this->assertSame($size[0], $size[1]);
        $this->assertGreaterThan(300, $size[0]);
    }

    /**
     * Every module survives the trip through JPEG.
     *
     * This is the one that matters: a lossy format smears exactly the sharp
     * black-to-white edges a reader measures, so the pixels are sampled back
     * at the centre of each module and compared against the code that was
     * meant to be drawn. A tag that loses even one module is a sticker on a
     * pen that never resolves to the goat.
     */
    public function test_the_jpeg_still_holds_every_module_of_the_code(): void
    {
        $goat = $this->listing([['weight_kg' => 15, 'tag' => 'BB-01']]);
        $weight = $goat->weights()->first();

        $matrix = Encoder::encode($weight->publicUrl(), ErrorCorrectionLevel::L())->getMatrix();

        $image = imagecreatefromstring($weight->qrJpeg(512));
        $quietZone = 4;
        $modulePixels = intdiv(imagesx($image), $matrix->getWidth() + ($quietZone * 2));

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $rgb = imagecolorsforindex($image, imagecolorat(
                    $image,
                    (($x + $quietZone) * $modulePixels) + intdiv($modulePixels, 2),
                    (($y + $quietZone) * $modulePixels) + intdiv($modulePixels, 2),
                ));

                $this->assertSame(
                    $matrix->get($x, $y) === 1,
                    $rgb['red'] < 128,
                    "The module at {$x},{$y} came out of the JPEG the wrong colour."
                );
            }
        }
    }

    /**
     * A white margin all the way round, because the scanner needs it.
     *
     * The quiet zone is part of the code rather than decoration: without it a
     * reader cannot tell where the code starts, and a tag cropped flush to the
     * black is a tag that fails at the gate.
     */
    public function test_a_tag_keeps_its_quiet_zone(): void
    {
        $goat = $this->listing([['weight_kg' => 15, 'tag' => 'BB-01']]);

        $image = imagecreatefromstring($goat->weights()->first()->qrJpeg(512));
        $side = imagesx($image);

        foreach ([[0, 0], [$side - 1, 0], [0, $side - 1], [$side - 1, $side - 1]] as [$x, $y]) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));

            $this->assertGreaterThan(
                240,
                min($rgb['red'], $rgb['green'], $rgb['blue']),
                "The corner at {$x},{$y} is not white, so the quiet zone is missing."
            );
        }
    }

    /**
     * Two goats at the same weight are two files, not one.
     *
     * A zip takes both entries under one name and most tools then show only
     * the last, so the pen would come up a tag short with nothing to say it
     * had. The ear tag is what tells them apart on the farm.
     */
    public function test_two_animals_of_the_same_weight_do_not_collapse_into_one_file(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 15, 'tag' => 'BB-01'],
            ['weight_kg' => 15, 'tag' => 'BB-02'],
            ['weight_kg' => 15],
            ['weight_kg' => 15],
        ]);

        $path = app(GoatQrArchive::class)->write($goat->weights()->orderBy('id')->get());
        $entries = $this->entries($path);
        @unlink($path);

        $this->assertCount(4, $entries);
        $this->assertCount(4, array_unique($entries));
        $this->assertContains('Black-Bengal-Buck-15kg.jpg', $entries);
        $this->assertContains('Black-Bengal-Buck-15kg-BB-02.jpg', $entries);
    }

    /** An empty archive is not a file, so it is refused rather than served. */
    public function test_a_listing_with_no_animals_cannot_be_archived(): void
    {
        $goat = $this->listing();

        $this->expectException(RuntimeException::class);

        app(GoatQrArchive::class)->write($goat->weights()->get());
    }

    /** The tag for one animal, from that animal's own row. */
    public function test_one_animals_code_downloads_from_its_row(): void
    {
        $goat = $this->listing([['weight_kg' => 15, 'tag' => 'BB-01']]);
        $weight = $goat->weights()->first();

        Livewire::actingAs($this->admin())
            ->test(WeightsRelationManager::class, [
                'ownerRecord' => $goat,
                'pageClass' => EditGoat::class,
            ])
            ->callAction(TestAction::make('downloadQr')->table($weight))
            ->assertFileDownloaded('Black-Bengal-Buck-15kg.jpg', $weight->qrJpeg(512), 'image/jpeg');
    }

    /** And the whole pen's tags from the header, in one zip. */
    public function test_the_whole_listing_downloads_as_one_archive(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 15, 'tag' => 'BB-01'],
            ['weight_kg' => 20, 'tag' => 'BB-03'],
        ]);

        Livewire::actingAs($this->admin())
            ->test(WeightsRelationManager::class, [
                'ownerRecord' => $goat,
                'pageClass' => EditGoat::class,
            ])
            ->callAction(TestAction::make('downloadAllQr')->table())
            ->assertFileDownloaded('Black-Bengal-Buck-QR-Codes.zip', contentType: 'application/zip');
    }

    /**
     * And just the ticked ones, for a reprint of two tags out of fifteen.
     *
     * Named after the listing all the same: the archive says which pen it is
     * for, and which animals are in it is the point of having ticked them.
     */
    public function test_only_the_selected_animals_can_be_archived(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 15, 'tag' => 'BB-01'],
            ['weight_kg' => 20, 'tag' => 'BB-03'],
            ['weight_kg' => 22.5, 'tag' => 'BB-04'],
        ]);

        $wanted = $goat->weights()->whereIn('weight_kg', [15, 22.5])->pluck('id')->all();

        Livewire::actingAs($this->admin())
            ->test(WeightsRelationManager::class, [
                'ownerRecord' => $goat,
                'pageClass' => EditGoat::class,
            ])
            ->set('selectedTableRecords', $wanted)
            ->callAction(TestAction::make('downloadQr')->table()->bulk())
            ->assertFileDownloaded('Black-Bengal-Buck-QR-Codes.zip', contentType: 'application/zip');
    }

    /** A listing with nothing on it does not offer the archive at all. */
    public function test_the_archive_button_is_hidden_while_there_are_no_animals(): void
    {
        Livewire::actingAs($this->admin())
            ->test(WeightsRelationManager::class, [
                'ownerRecord' => $this->listing(),
                'pageClass' => EditGoat::class,
            ])
            ->assertActionHidden(TestAction::make('downloadAllQr')->table());
    }
}
