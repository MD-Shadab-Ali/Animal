<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Goat;
use App\Models\GoatImage;
use Database\Seeders\Concerns\SeedsMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    use SeedsMedia;

    public function run(): void
    {
        $categories = [
            ['Dashain Goats', 'Khasi and boka raised for Dashain — weighed on a calibrated scale and vet-checked.', true],
            ['Qurbani Goats', 'Healthy, well-fed goats that meet Qurbani requirements — age and teeth verified.', true],
            ['Dairy Goats', 'High-yield milking does from proven dairy lines.', true],
            ['Breeding Stock', 'Bucks and does selected for temperament, frame and fertility.', true],
            ['Young Kids', 'Weaned kids ready to raise on your own farm.', false],
            ['Premium Selection', 'Show-quality animals with exceptional weight and conformation.', true],
        ];

        $categoryIds = [];

        foreach ($categories as $i => [$name, $description, $featured]) {
            $slug = Str::slug($name);

            $category = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'             => $name,
                    'image'            => $this->seedImage("categories/{$slug}.jpg"),
                    'description'      => $description,
                    'is_active'        => true,
                    'is_featured'      => $featured,
                    'sort_order'       => $i,
                    'meta_title'       => $name.' for sale',
                    'meta_description' => $description,
                ]
            );

            $categoryIds[$name] = $category->id;
        }

        $goats = [
            [
                'name' => 'Black Bengal Buck', 'category' => 'Dashain Goats', 'breed' => 'Black Bengal',
                'age' => 14, 'weight' => 22.5, 'gender' => 'male', 'color' => 'Black', 'teeth' => 2,
                'price' => 28000, 'sale' => 24500, 'featured' => true,
                'short' => 'Compact, hardy and famously tender meat. Vet-checked and dewormed.',
                'images' => ['black-bengal-buck-1', 'black-bengal-buck-2'],
            ],
            [
                'name' => 'Jamunapari Buck', 'category' => 'Premium Selection', 'breed' => 'Jamunapari',
                'age' => 20, 'weight' => 45.0, 'gender' => 'male', 'color' => 'Fawn with white markings', 'teeth' => 4,
                'price' => 72000, 'sale' => null, 'featured' => true,
                'short' => 'Tall Roman-nosed buck with excellent frame and long pendulous ears.',
                'images' => ['jamunapari-buck-1'],
            ],
            [
                'name' => 'Beetal Doe', 'category' => 'Dairy Goats', 'breed' => 'Beetal',
                'age' => 24, 'weight' => 38.0, 'gender' => 'female', 'color' => 'Black', 'teeth' => 4,
                'price' => 55000, 'sale' => 51000, 'featured' => true,
                'short' => 'Proven milker averaging 2.2 litres a day through peak lactation.',
                'images' => ['beetal-doe-1', 'beetal-doe-2'],
            ],
            [
                'name' => 'Boer Cross Buck', 'category' => 'Premium Selection', 'breed' => 'Boer Cross',
                'age' => 22, 'weight' => 52.0, 'gender' => 'male', 'color' => 'White with red head', 'teeth' => 4,
                'price' => 95000, 'sale' => null, 'featured' => true,
                'short' => 'Heavy meat breed cross with outstanding daily weight gain.',
                'images' => ['boer-cross-buck-1', 'boer-cross-buck-2'],
            ],
            [
                'name' => 'Barbari Buck', 'category' => 'Qurbani Goats', 'breed' => 'Barbari',
                'age' => 12, 'weight' => 18.0, 'gender' => 'male', 'color' => 'White with brown spots', 'teeth' => 2,
                'price' => 21000, 'sale' => null, 'featured' => false,
                'short' => 'Small-framed, fast-maturing and easy to handle in a city yard.',
                'images' => ['barbari-buck-1'],
            ],
            [
                'name' => 'Sirohi Doe', 'category' => 'Breeding Stock', 'breed' => 'Sirohi',
                'age' => 26, 'weight' => 33.0, 'gender' => 'female', 'color' => 'Reddish brown', 'teeth' => 6,
                'price' => 44000, 'sale' => 39500, 'featured' => false,
                'short' => 'Hardy doe with a clean kidding record — twins in both seasons.',
                'images' => ['sirohi-doe-1'],
            ],
            [
                'name' => 'Totapuri Buck', 'category' => 'Premium Selection', 'breed' => 'Totapuri',
                'age' => 19, 'weight' => 41.0, 'gender' => 'male', 'color' => 'White', 'teeth' => 4,
                'price' => 68000, 'sale' => null, 'featured' => false,
                'short' => 'Distinctive parrot-beak profile and a striking show presence.',
                'images' => ['totapuri-buck-1'],
            ],
            [
                'name' => 'Black Bengal Kid', 'category' => 'Young Kids', 'breed' => 'Black Bengal',
                'age' => 5, 'weight' => 9.0, 'gender' => 'female', 'color' => 'Black', 'teeth' => 0,
                'price' => 11000, 'sale' => null, 'featured' => false,
                'short' => 'Weaned kid, vaccinated and ready to raise.',
                'images' => ['black-bengal-kid-1'],
            ],
            [
                'name' => 'Jamunapari Doe', 'category' => 'Dairy Goats', 'breed' => 'Jamunapari',
                'age' => 28, 'weight' => 40.0, 'gender' => 'female', 'color' => 'White', 'teeth' => 6,
                'price' => 62000, 'sale' => null, 'featured' => false,
                'short' => 'Long-lactation doe, steady yield and a calm milking temperament.',
                'images' => ['jamunapari-doe-1', 'jamunapari-doe-2', 'jamunapari-doe-3'],
            ],
            [
                'name' => 'Cross Breed Khasi', 'category' => 'Dashain Goats', 'breed' => 'Local Cross',
                'age' => 16, 'weight' => 30.0, 'gender' => 'male', 'color' => 'Brown and white', 'teeth' => 2,
                'price' => 38000, 'sale' => 35000, 'featured' => false,
                'short' => 'Well-muscled cross raised entirely on green fodder.',
                'images' => ['cross-breed-khasi-1', 'cross-breed-khasi-2'],
            ],
            [
                'name' => 'Khari Khasi', 'category' => 'Dashain Goats', 'breed' => 'Khari',
                'age' => 16, 'weight' => 28.0, 'gender' => 'male', 'color' => 'Black', 'teeth' => 2,
                'price' => 34000, 'sale' => 31500, 'featured' => true,
                'short' => 'Native Khari khasi, hill-raised on open grazing. The classic Dashain animal.',
                'images' => ['khari-khasi-light-1'],
            ],
            [
                'name' => 'Khari Khasi', 'category' => 'Dashain Goats', 'breed' => 'Khari',
                'age' => 19, 'weight' => 34.0, 'gender' => 'male', 'color' => 'Black and tan', 'teeth' => 4,
                'price' => 42000, 'sale' => null, 'featured' => true,
                'short' => 'Heavier Khari khasi with good fat cover, ready for a large family.',
                'images' => ['khari-khasi-heavy-1'],
            ],
        ];

        foreach ($goats as $i => $g) {
            $description = '<p>'.$g['short'].'</p>'
                .'<p>This animal was raised on our own farm, examined by a licensed veterinarian, '
                .'dewormed and vaccinated. Weight is recorded on a calibrated livestock scale on the '
                .'day of listing and may vary slightly by the delivery date.</p>'
                .'<p>Delivery is arranged by our own transport inside Kathmandu Valley and by livestock courier '
                .'elsewhere. Payment is cash on delivery.</p>';

            $goat = Goat::updateOrCreate(
                ['sku' => 'GT-'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT)],
                [
                    'category_id'   => $categoryIds[$g['category']],
                    'name'          => $g['name'],
                    // First file is the card photo; the rest become the gallery below.
                    'thumbnail'     => $this->seedImage('goats/'.$g['images'][0].'.jpg'),
                    // Names repeat now that the weight is out of them, so the
                    // slug keeps it: it is a URL, not something shown beside
                    // the weight the buyer chose.
                    'slug'          => Str::slug($g['name'].' '.$g['weight'].'kg'),
                    'breed'         => $g['breed'],
                    'age_months'    => $g['age'],
                    'weight_kg'     => $g['weight'],
                    'gender'        => $g['gender'],
                    'color'         => $g['color'],
                    'teeth'         => $g['teeth'],
                    'health_status' => 'Vet checked — healthy',
                    'is_vaccinated' => true,
                    'specs'         => [
                        ['label' => 'Feed', 'value' => 'Green fodder + grain mix'],
                        ['label' => 'Deworming', 'value' => 'Completed'],
                        ['label' => 'Origin', 'value' => 'Own farm, Budhanilkantha'],
                    ],
                    'price'             => $g['price'],
                    'sale_price'        => $g['sale'],
                    'stock'             => 1,
                    'track_stock'       => true,
                    'short_description' => $g['short'],
                    'description'       => $description,
                    'status'            => 'published',
                    'is_featured'       => $g['featured'],
                    'sort_order'        => $i,
                    'meta_title'        => $g['name'],
                    'meta_description'  => $g['short'],
                ]
            );

            // Keyed on the slot rather than the path: keying on the path would
            // orphan the old row every time a photo is swapped out.
            foreach (array_slice($g['images'], 1) as $slot => $file) {
                $path = $this->seedImage('goats/'.$file.'.jpg');

                if ($path === null) {
                    continue;
                }

                GoatImage::updateOrCreate(
                    ['goat_id' => $goat->id, 'sort_order' => $slot],
                    ['path' => $path, 'alt' => $g['name'].' — '.$g['breed']],
                );
            }
        }
    }
}
