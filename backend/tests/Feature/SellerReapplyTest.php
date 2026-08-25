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

class SellerReapplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function applicant(): User
    {
        return User::create([
            'name' => 'Second Chance', 'email' => 'reapply@example.test',
            'phone' => '+880 1700-444444', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'farm_name'     => 'First Attempt Farm',
            'contact_phone' => '+880 1700-444444',
            'city'          => 'Khulna',
            'national_id'   => '1990777888999',
            'id_document'   => UploadedFile::fake()->image('nid.jpg'),
        ], $overrides);
    }

    public function test_a_user_can_apply_again_after_their_application_is_deleted(): void
    {
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();

        // An admin removes the application from the panel.
        $seller->delete();
        $this->assertSoftDeleted('sellers', ['id' => $seller->id]);

        // The profile endpoint should behave as if they never applied.
        $this->getJson('/api/v1/seller/profile')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('code', 'not_a_seller');

        // And a fresh application must go through.
        $this->postJson('/api/v1/seller/apply', $this->payload([
            'farm_name'   => 'Second Attempt Farm',
            'city'        => 'Jessore',
            'id_document' => UploadedFile::fake()->image('nid-again.jpg'),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.farm_name', 'Second Attempt Farm');

        $fresh = Seller::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Second Attempt Farm', $fresh->farm_name);
        $this->assertSame('Jessore', $fresh->city);
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_a_live_application_still_blocks_a_second_one(): void
    {
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'farm_name'   => 'Sneaky Second Farm',
            'id_document' => UploadedFile::fake()->image('again.jpg'),
        ]))->assertStatus(422)->assertJsonValidationErrors('farm_name');

        $this->assertSame(1, Seller::where('user_id', $user->id)->count());
    }

    public function test_resubmitting_sends_old_listings_back_through_moderation(): void
    {
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();
        $seller->update(['status' => 'approved', 'approved_at' => now()]);

        $live = Goat::create([
            'category_id' => Category::first()->id, 'seller_id' => $seller->id,
            'name' => 'Previously Approved Goat', 'gender' => 'male', 'price' => 20000,
            'stock' => 1, 'status' => 'published', 'approval_status' => 'approved',
        ]);

        $sold = Goat::create([
            'category_id' => Category::first()->id, 'seller_id' => $seller->id,
            'name' => 'Already Sold Goat', 'gender' => 'male', 'price' => 25000,
            'stock' => 0, 'status' => 'sold', 'approval_status' => 'approved',
        ]);

        $seller->delete();

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'farm_name'   => 'Second Attempt Farm',
            'id_document' => UploadedFile::fake()->image('nid-again.jpg'),
        ]))->assertCreated();

        // The live listing must be re-reviewed rather than springing back.
        $this->assertSame('pending', $live->fresh()->approval_status);
        $this->assertFalse(Goat::published()->whereKey($live->id)->exists());

        // History is left alone.
        $this->assertSame('approved', $sold->fresh()->approval_status);
        $this->assertSame('sold', $sold->fresh()->status);
    }

    public function test_the_slug_follows_a_renamed_farm_without_colliding(): void
    {
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('first-attempt-farm', $seller->slug);

        $seller->delete();

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'farm_name'   => 'Second Attempt Farm',
            'id_document' => UploadedFile::fake()->image('nid-again.jpg'),
        ]))->assertCreated();

        $this->assertSame('second-attempt-farm', $seller->fresh()->slug);
    }

    public function test_resubmitting_the_same_name_keeps_a_clean_slug(): void
    {
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();
        $seller->delete();

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'id_document' => UploadedFile::fake()->image('nid-again.jpg'),
        ]))->assertCreated();

        // Must not drift to first-attempt-farm-2 by colliding with itself.
        $this->assertSame('first-attempt-farm', $seller->fresh()->slug);
    }
}
