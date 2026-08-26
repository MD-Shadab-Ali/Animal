<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Goat;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a seller's own listing says about itself.
 *
 * A sold goat keeps `approval_status = approved`, so any screen reading
 * approval alone kept advertising it as "Live" after it had gone — leaving the
 * seller unable to tell what was still for sale.
 */
class SellerListingStateTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $user = User::create([
            'name' => 'State Farm', 'email' => 'statefarm@example.test',
            'phone' => '+977 9800-777777', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        $this->seller = Seller::create([
            'user_id' => $user->id,
            'farm_name' => 'State Farm',
            'contact_phone' => '+977 9800-777777',
            'city' => 'Kathmandu',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function listing(string $name, string $status, string $approval): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id,
            'seller_id' => $this->seller->id,
            'name' => $name,
            'gender' => 'male',
            'price' => 25000,
            'stock' => $status === 'sold' ? 0 : 1,
            'track_stock' => true,
            'status' => $status,
            'approval_status' => $approval,
        ]);
    }

    private function states(): array
    {
        Sanctum::actingAs($this->seller->user);

        return collect($this->getJson('/api/v1/seller/listings')->assertOk()->json('data'))
            ->pluck('state', 'name')
            ->all();
    }

    public function test_a_sold_goat_is_not_described_as_live(): void
    {
        $this->listing('Black Goat', 'sold', 'approved');
        $this->listing('Still For Sale', 'published', 'approved');

        $states = $this->states();

        // Both are approved; only one can still be bought.
        $this->assertSame('sold', $states['Black Goat']);
        $this->assertSame('live', $states['Still For Sale']);
    }

    public function test_every_listing_reports_the_state_a_seller_would_recognise(): void
    {
        $this->listing('A Draft', 'draft', 'draft');
        $this->listing('Under Review', 'draft', 'pending');
        $this->listing('Needs Work', 'draft', 'rejected');
        $this->listing('On Sale', 'published', 'approved');
        $this->listing('Taken Down', 'draft', 'approved');
        $this->listing('Gone', 'sold', 'approved');

        $this->assertSame([
            'A Draft'      => 'draft',
            'Under Review' => 'pending',
            'Needs Work'   => 'rejected',
            'On Sale'      => 'live',
            'Taken Down'   => 'hidden',
            'Gone'         => 'sold',
        ], $this->states());
    }

    public function test_the_seller_can_filter_down_to_what_has_sold(): void
    {
        $this->listing('Gone', 'sold', 'approved');
        $this->listing('On Sale', 'published', 'approved');

        Sanctum::actingAs($this->seller->user);

        $sold = $this->getJson('/api/v1/seller/listings?state=sold')->assertOk()->json('data');

        $this->assertCount(1, $sold);
        $this->assertSame('Gone', $sold[0]['name']);

        $live = $this->getJson('/api/v1/seller/listings?state=live')->assertOk()->json('data');

        $this->assertCount(1, $live);
        $this->assertSame('On Sale', $live[0]['name']);
    }

    /** A sold goat must not turn up under any of the pre-sale filters. */
    public function test_a_sold_goat_is_absent_from_the_approval_filters(): void
    {
        $this->listing('Gone', 'sold', 'approved');

        Sanctum::actingAs($this->seller->user);

        foreach (['live', 'draft', 'pending', 'rejected'] as $state) {
            $this->assertSame(
                [],
                $this->getJson('/api/v1/seller/listings?state='.$state)->assertOk()->json('data'),
                "A sold goat should not appear under {$state}"
            );
        }
    }

    /**
     * A seller could describe a goat but never show one.
     *
     * The gallery and `goats.thumbnail` both existed, but only staff could fill
     * them — so every seller listing sat behind the empty placeholder icon.
     */
    public function test_a_seller_can_put_photos_on_a_draft(): void
    {
        Storage::fake('public');

        $goat = $this->listing('Photogenic Goat', 'draft', 'draft');

        Sanctum::actingAs($this->seller->user);

        $data = $this->postJson('/api/v1/seller/listings/'.$goat->id.'/images', [
            'images' => [
                UploadedFile::fake()->image('front.jpg'),
                UploadedFile::fake()->image('side.jpg'),
            ],
        ])->assertCreated()->json('data');

        $this->assertCount(2, $data['images']);

        // The first one becomes what the shop grid and every order line show.
        $goat->refresh();
        $this->assertNotNull($goat->thumbnail);
        $this->assertSame($goat->images()->orderBy('sort_order')->value('path'), $goat->thumbnail);
        Storage::disk('public')->assertExists($goat->thumbnail);
    }

    public function test_removing_the_main_photo_promotes_the_next_one(): void
    {
        Storage::fake('public');

        $goat = $this->listing('Photogenic Goat', 'draft', 'draft');

        Sanctum::actingAs($this->seller->user);

        $this->postJson('/api/v1/seller/listings/'.$goat->id.'/images', [
            'images' => [
                UploadedFile::fake()->image('front.jpg'),
                UploadedFile::fake()->image('side.jpg'),
            ],
        ])->assertCreated();

        $goat->refresh();
        $first = $goat->images()->orderBy('sort_order')->first();

        $this->deleteJson('/api/v1/seller/listings/'.$goat->id.'/images/'.$first->id)->assertOk();

        $goat->refresh();

        $this->assertCount(1, $goat->images);
        // Never left pointing at a file that has gone.
        $this->assertSame($goat->images()->value('path'), $goat->thumbnail);
        Storage::disk('public')->assertMissing($first->path);
    }

    /** Locked once approved, for the same reason the fields are. */
    public function test_photos_cannot_be_changed_on_an_approved_listing(): void
    {
        Storage::fake('public');

        $goat = $this->listing('On Sale', 'published', 'approved');

        Sanctum::actingAs($this->seller->user);

        $this->postJson('/api/v1/seller/listings/'.$goat->id.'/images', [
            'images' => [UploadedFile::fake()->image('sneaky.jpg')],
        ])->assertStatus(422);

        $this->assertCount(0, $goat->fresh()->images);
    }

    public function test_a_seller_cannot_add_photos_to_someone_elses_goat(): void
    {
        Storage::fake('public');

        $other = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Not Mine', 'gender' => 'male', 'price' => 1000,
            'stock' => 1, 'track_stock' => true,
            'status' => 'draft', 'approval_status' => 'draft',
        ]);

        Sanctum::actingAs($this->seller->user);

        $this->postJson('/api/v1/seller/listings/'.$other->id.'/images', [
            'images' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertForbidden();
    }

    public function test_only_images_are_accepted(): void
    {
        Storage::fake('public');

        $goat = $this->listing('Photogenic Goat', 'draft', 'draft');

        Sanctum::actingAs($this->seller->user);

        $this->postJson('/api/v1/seller/listings/'.$goat->id.'/images', [
            'images' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

}
