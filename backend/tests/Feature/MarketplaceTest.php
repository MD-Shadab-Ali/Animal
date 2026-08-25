<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function customer(string $email = 'seller-person@example.test'): User
    {
        return User::create([
            'name' => 'Karim Farms', 'email' => $email, 'phone' => '+880 1700-222222',
            'password' => 'password', 'role' => 'customer', 'is_active' => true,
        ]);
    }

    private function approvedSeller(?User $user = null): Seller
    {
        $user ??= $this->customer();

        return Seller::create([
            'user_id' => $user->id,
            'farm_name' => 'Karim Livestock',
            'contact_phone' => '+880 1700-222222',
            'city' => 'Dhaka',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function listingPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => Category::first()->id,
            'name'        => 'Karim Black Bengal — 20kg',
            'breed'       => 'Black Bengal',
            'gender'      => 'male',
            'age_months'  => 14,
            'weight_kg'   => 20,
            'price'       => 30000,
            'stock'       => 1,
            'short_description' => 'Raised on green fodder.',
        ], $overrides);
    }

    public function test_a_customer_can_apply_to_sell(): void
    {
        Storage::fake('public');

        $user = $this->customer();
        Sanctum::actingAs($user);

        // An ID document is part of the application; see SellerDocumentsTest.
        $application = [
            'farm_name'     => 'Karim Livestock',
            'contact_phone' => '+880 1700-222222',
            'city'          => 'Dhaka',
            'national_id'   => '1990123456789',
            'id_document'   => UploadedFile::fake()->image('nid.jpg'),
        ];

        $this->postJson('/api/v1/seller/apply', $application)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('sellers', ['user_id' => $user->id, 'status' => 'pending']);

        // Applying twice is refused.
        $this->postJson('/api/v1/seller/apply', array_merge($application, [
            'farm_name'   => 'Another Farm',
            'id_document' => UploadedFile::fake()->image('nid-2.jpg'),
        ]))->assertStatus(422);
    }

    public function test_an_unapproved_seller_cannot_touch_listings(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        Seller::create([
            'user_id' => $user->id, 'farm_name' => 'Pending Farm',
            'contact_phone' => '+880 1700-222222', 'city' => 'Dhaka', 'status' => 'pending',
        ]);

        $this->getJson('/api/v1/seller/listings')->assertForbidden()
            ->assertJsonPath('code', 'seller_pending');

        $this->postJson('/api/v1/seller/listings', $this->listingPayload())->assertForbidden();

        // But they can still read their own profile to see where they stand.
        $this->getJson('/api/v1/seller/profile')->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_someone_without_a_seller_account_is_refused(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/seller/listings')->assertForbidden()
            ->assertJsonPath('code', 'not_a_seller');
    }

    public function test_a_listing_is_invisible_until_staff_approve_it(): void
    {
        $seller = $this->approvedSeller();
        Sanctum::actingAs($seller->user);

        // Draft — created, but nowhere near the shop.
        $listing = $this->postJson('/api/v1/seller/listings', $this->listingPayload())
            ->assertCreated()
            ->assertJsonPath('data.approval_status', 'draft')
            ->assertJsonPath('data.is_live', false)
            ->json('data');

        $goat = Goat::findOrFail($listing['id']);
        $this->assertPublicCannotSee($goat);

        // Submitted — waiting on staff, still not public.
        $this->postJson("/api/v1/seller/listings/{$goat->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');

        $this->assertPublicCannotSee($goat->fresh());

        // Approved — now it is a real listing.
        $goat->update(['approval_status' => 'approved', 'approved_at' => now()]);

        $this->getJson('/api/v1/goats/'.$goat->slug)->assertOk();
        $this->assertTrue(Goat::published()->whereKey($goat->id)->exists());
    }

    public function test_an_approved_listing_cannot_be_edited_behind_moderation(): void
    {
        $seller = $this->approvedSeller();
        Sanctum::actingAs($seller->user);

        $goat = Goat::create($this->listingPayload() + [
            'seller_id' => $seller->id,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        $this->putJson("/api/v1/seller/listings/{$goat->id}", $this->listingPayload(['price' => 1]))
            ->assertStatus(422);

        $this->assertEquals(30000, $goat->fresh()->price);
    }

    public function test_a_seller_cannot_reach_another_sellers_listing(): void
    {
        $mine = $this->approvedSeller();
        $theirs = $this->approvedSeller($this->customer('other@example.test'));

        $theirGoat = Goat::create($this->listingPayload() + [
            'seller_id' => $theirs->id, 'status' => 'draft', 'approval_status' => 'draft',
        ]);

        Sanctum::actingAs($mine->user);

        $this->getJson("/api/v1/seller/listings/{$theirGoat->id}")->assertForbidden();
        $this->putJson("/api/v1/seller/listings/{$theirGoat->id}", $this->listingPayload())->assertForbidden();
        $this->deleteJson("/api/v1/seller/listings/{$theirGoat->id}")->assertForbidden();
    }

    public function test_suspending_a_seller_pulls_their_goats_from_the_shop(): void
    {
        $seller = $this->approvedSeller();

        $goat = Goat::create($this->listingPayload() + [
            'seller_id' => $seller->id, 'status' => 'published', 'approval_status' => 'approved',
        ]);

        $this->assertTrue(Goat::published()->whereKey($goat->id)->exists());

        $seller->update(['status' => 'suspended']);

        $this->assertFalse(Goat::published()->whereKey($goat->id)->exists());
        $this->getJson('/api/v1/goats/'.$goat->slug)->assertNotFound();
    }

    private function assertPublicCannotSee(Goat $goat): void
    {
        $this->assertFalse(
            Goat::published()->whereKey($goat->id)->exists(),
            "Goat {$goat->id} should not be publicly visible"
        );

        $this->getJson('/api/v1/goats/'.$goat->slug)->assertNotFound();
    }
}
