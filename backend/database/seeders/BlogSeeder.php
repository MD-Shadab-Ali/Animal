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

        // The article itself lives in database/seeders/content/posts, in a file
        // named after the slug the title produces. See body().
        $posts = [
            [
                'Care guides',
                'How to house a goat in a city yard',
                'A goat needs less space than people expect, but it does need the right space.',
            ],
            [
                'Care guides',
                'A feeding schedule that actually works',
                'Green fodder, a grain top-up and constant clean water — in that order of importance.',
            ],
            [
                'Buying advice',
                'Checking a goat before you buy: teeth, weight and gait',
                'Three checks that tell you most of what you need to know in under two minutes.',
            ],
        ];

        $covers = [
            'house-a-goat-in-a-city-yard',
            'feeding-schedule',
            'checking-a-goat-before-buying',
        ];

        foreach ($posts as $i => [$category, $title, $excerpt]) {
            $slug = Str::slug($title);

            $attributes = [
                'post_category_id' => $categories[$category],
                'user_id'          => $author?->id,
                'title'            => $title,
                'excerpt'          => $excerpt,
                'cover_image'      => isset($covers[$i])
                    ? $this->seedImage("posts/{$covers[$i]}.jpg")
                    : null,
                'is_published'     => true,
                'is_featured'      => $i === 0,
                'published_at'     => now()->subDays(($i + 1) * 4),
                'meta_title'       => $title,
                'meta_description' => $excerpt,
            ];

            // Written only when the file is actually there. Passing null would
            // blank an article that is already in the database, which is a
            // worse outcome than leaving the existing copy standing.
            if (($body = $this->body($slug)) !== null) {
                $attributes['body'] = $body;
            }

            Post::updateOrCreate(['slug' => $slug], $attributes);
        }
    }

    /**
     * The article HTML for a post, read from the file named after its slug.
     *
     * The guides run to a couple of thousand words each. Inlined, they would
     * bury the twenty lines of seeding underneath them, and every edit to the
     * copy would land in a diff as one enormous changed string. Kept as .html
     * beside this seeder, an article reads and reviews as an article.
     *
     * The markup in those files is deliberately limited to what the Filament
     * rich editor round-trips -- headings, paragraphs, lists, blockquotes,
     * tables and links. Anything outside that vocabulary is dropped the first
     * time an admin opens the post and saves it.
     */
    protected function body(string $slug): ?string
    {
        $path = database_path("seeders/content/posts/{$slug}.html");

        if (! is_file($path)) {
            $this->command?->warn("Post body missing, skipped: {$slug}.html");

            return null;
        }

        return trim((string) file_get_contents($path));
    }
}
