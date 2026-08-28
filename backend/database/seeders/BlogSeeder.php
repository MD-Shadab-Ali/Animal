<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use Database\Seeders\Concerns\SeedsMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    use SeedsMedia;

    public function run(): void
    {
        $author = User::where('role', 'admin')->first();

        $categories = [];
        foreach (['Care guides', 'Buying advice', 'Farm news'] as $name) {
            $categories[$name] = PostCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true]
            )->id;
        }

        $posts = [
            [
                'Care guides',
                'How to house a goat in a city yard',
                'A goat needs less space than people expect, but it does need the right space.',
                '<p>A single adult goat needs about twelve square feet of dry, shaded shelter and roughly twice that in open run. What matters more than the size is the floor: goats develop foot rot fast on ground that stays wet.</p><h3>The floor</h3><p>Raise the sleeping area at least six inches above the surrounding ground and bed it with straw or rice husk. Change bedding weekly.</p><h3>Shade and airflow</h3><p>A tin roof without ventilation turns into an oven by noon. Leave at least a foot of open gap under the eaves on two sides.</p><h3>Fencing</h3><p>Goats climb and lean. Four feet of woven wire, well strained, holds most animals. Chain link sags within a season.</p>',
            ],
            [
                'Care guides',
                'A feeding schedule that actually works',
                'Green fodder, a grain top-up and constant clean water — in that order of importance.',
                '<p>Most goats brought home from a market lose condition in the first fortnight, almost always because the feed changed overnight. Move gradually across a week.</p><h3>Daily green fodder</h3><p>Aim for three to four kilos of fresh green fodder per adult per day, split into two feeds. Napier, jackfruit leaf and kolmi all work well here.</p><h3>Grain, sparingly</h3><p>Two hundred to three hundred grams of a wheat bran and khesari mix is enough for a maintenance animal. More than that invites bloat.</p><h3>Water</h3><p>Clean water, always available, changed twice daily. A goat that drinks less eats less.</p>',
            ],
            [
                'Buying advice',
                'Checking a goat before you buy: teeth, weight and gait',
                'Three checks that tell you most of what you need to know in under two minutes.',
                '<p>You do not need to be a vet to spot a bad animal. Three quick checks catch most problems.</p><h3>Teeth</h3><p>The permanent incisors tell you the age. Two broad teeth means roughly twelve to eighteen months, four means about two years. For both Dashain and Qurbani buyers this matters, so ask to see the mouth.</p><h3>Weight against frame</h3><p>Run a hand along the spine and ribs. You should feel them under a layer of cover, not see them, and not lose them under fat.</p><h3>Gait</h3><p>Walk the animal a few steps. Any limp, stiffness or reluctance to bear weight is a reason to walk away, however good the price.</p>',
            ],
        ];

        $covers = [
            'house-a-goat-in-a-city-yard',
            'feeding-schedule',
            'checking-a-goat-before-buying',
        ];

        foreach ($posts as $i => [$category, $title, $excerpt, $body]) {
            Post::updateOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'post_category_id' => $categories[$category],
                    'user_id'          => $author?->id,
                    'title'            => $title,
                    'excerpt'          => $excerpt,
                    'body'             => $body,
                    'cover_image'      => isset($covers[$i])
                        ? $this->seedImage("posts/{$covers[$i]}.jpg")
                        : null,
                    'is_published'     => true,
                    'is_featured'      => $i === 0,
                    'published_at'     => now()->subDays(($i + 1) * 4),
                    'meta_title'       => $title,
                    'meta_description' => $excerpt,
                ]
            );
        }
    }
}
