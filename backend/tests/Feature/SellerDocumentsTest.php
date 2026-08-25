<?php

namespace Tests\Feature;

use App\Filament\Resources\Sellers\Pages\EditSeller;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class SellerDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function seller(): Seller
    {
        return Seller::firstOrFail();
    }

    /** Staff vet sellers on these fields, so they must actually appear in the panel. */
    public function test_identity_fields_are_visible_in_the_admin_form(): void
    {
        $seller = $this->seller();

        $seller->update([
            'national_id'           => '1990123456789',
            'id_document'           => 'sellers/documents/id.jpg',
            'trade_licence'         => 'sellers/documents/licence.pdf',
            'payout_account_number' => '017111122223',
        ]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(EditSeller::class, ['record' => $seller->getRouteKey()])
            ->assertFormSet([
                'national_id'           => '1990123456789',
                'payout_account_number' => '017111122223',
            ])
            // FileUpload keeps its state as a keyed array, so check the paths are in there.
            ->assertFormSet(function (array $state): bool {
                $paths = collect([$state['id_document'] ?? [], $state['trade_licence'] ?? []])
                    ->flatMap(fn ($value) => is_array($value) ? array_values($value) : [$value])
                    ->all();

                return in_array('sellers/documents/id.jpg', $paths, true)
                    && in_array('sellers/documents/licence.pdf', $paths, true);
            });
    }

    /** The same fields must never reach the public seller directory. */
    public function test_documents_never_leak_through_the_public_api(): void
    {
        $seller = $this->seller();

        $seller->update([
            'national_id'           => '1990123456789',
            'id_document'           => 'sellers/documents/id.jpg',
            'trade_licence'         => 'sellers/documents/licence.pdf',
            'payout_account_number' => '017111122223',
        ]);

        $response = $this->getJson('/api/v1/sellers/'.$seller->slug)->assertOk();

        foreach (['1990123456789', 'sellers/documents/id.jpg', 'sellers/documents/licence.pdf', '017111122223'] as $secret) {
            $response->assertDontSee($secret, false);
        }

        $this->getJson('/api/v1/sellers')->assertOk()->assertDontSee('1990123456789', false);
    }

    private function applicant(): User
    {
        return User::create([
            'name' => 'New Applicant', 'email' => 'applicant@example.test',
            'phone' => '+880 1700-333333', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'farm_name'     => 'Applicant Farm',
            'contact_phone' => '+880 1700-333333',
            'city'          => 'Bogura',
            'national_id'   => '1990123456789',
            'id_document'   => UploadedFile::fake()->image('nid.jpg'),
        ], $overrides);
    }

    public function test_an_application_without_an_id_document_is_refused(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->applicant());

        $payload = $this->payload();
        unset($payload['id_document']);

        $this->postJson('/api/v1/seller/apply', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_document')
            ->assertJsonPath('errors.id_document.0', 'Please attach a photo or scan of your ID.');

        $this->assertDatabaseCount('sellers', 1); // only the seeded one
    }

    public function test_the_national_id_number_is_also_required(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->applicant());

        $payload = $this->payload();
        unset($payload['national_id']);

        $this->postJson('/api/v1/seller/apply', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('national_id');
    }

    public function test_an_application_succeeds_without_a_trade_licence(): void
    {
        Storage::fake('public');
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('1990123456789', $seller->national_id);
        $this->assertNotNull($seller->id_document, 'The ID document path should be stored');
        $this->assertNull($seller->trade_licence, 'A trade licence is optional');

        Storage::disk('public')->assertExists($seller->id_document);
    }

    public function test_a_trade_licence_is_stored_when_supplied(): void
    {
        Storage::fake('public');
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'trade_licence' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ]))->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();

        $this->assertNotNull($seller->trade_licence);
        Storage::disk('public')->assertExists($seller->trade_licence);
    }

    public function test_unsupported_files_are_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->applicant());

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'id_document' => UploadedFile::fake()->create('virus.exe', 20),
        ]))->assertStatus(422)->assertJsonValidationErrors('id_document');
    }

    public function test_oversized_files_are_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->applicant());

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'id_document' => UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'),
        ]))->assertStatus(422)->assertJsonValidationErrors('id_document');
    }

    public function test_a_seller_can_replace_their_documents_and_the_old_file_goes(): void
    {
        Storage::fake('public');
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();
        $original = $seller->id_document;

        $this->postJson('/api/v1/seller/documents', [
            'id_document' => UploadedFile::fake()->image('clearer-nid.jpg'),
        ])->assertOk();

        $seller->refresh();

        $this->assertNotSame($original, $seller->id_document);
        Storage::disk('public')->assertExists($seller->id_document);
        Storage::disk('public')->assertMissing($original);
    }

    public function test_the_owner_can_see_their_own_documents_but_the_public_cannot(): void
    {
        Storage::fake('public');
        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload())->assertCreated();

        $this->getJson('/api/v1/seller/profile')
            ->assertOk()
            ->assertJsonPath('data.national_id', '1990123456789')
            ->assertJsonStructure(['data' => ['documents' => ['id_document' => ['url', 'name']]]]);
    }

    /**
     * Every field the application form collects must survive the round trip and
     * be visible to staff. Guards against a field being accepted by the API but
     * never surfaced in the panel.
     */
    public function test_the_whole_application_reaches_the_admin_panel(): void
    {
        Storage::fake('public');

        $user = $this->applicant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/apply', $this->payload([
            'contact_email' => 'farm@example.test',
            'address_line'  => 'Village Kachia, Ward 3',
            'area'          => 'Saturia',
            'city'          => 'Manikganj',
            'postal_code'   => '1810',
            'bio'           => 'Three generations of goat farming.',
            'trade_licence' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
        ]))->assertCreated();

        $seller = Seller::where('user_id', $user->id)->firstOrFail();

        // Stored as submitted.
        $this->assertSame('Applicant Farm', $seller->farm_name);
        $this->assertSame('farm@example.test', $seller->contact_email);
        $this->assertSame('Village Kachia, Ward 3', $seller->address_line);
        $this->assertSame('Saturia', $seller->area);
        $this->assertSame('Manikganj', $seller->city);
        $this->assertSame('1810', $seller->postal_code);
        $this->assertSame('1990123456789', $seller->national_id);
        $this->assertSame('Three generations of goat farming.', $seller->bio);
        $this->assertNotNull($seller->id_document);
        $this->assertNotNull($seller->trade_licence);

        // And every one of them is filled into the admin form.
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(EditSeller::class, ['record' => $seller->getRouteKey()])
            ->assertFormSet([
                'farm_name'     => 'Applicant Farm',
                'contact_email' => 'farm@example.test',
                'address_line'  => 'Village Kachia, Ward 3',
                'area'          => 'Saturia',
                'city'          => 'Manikganj',
                'postal_code'   => '1810',
                'national_id'   => '1990123456789',
                'bio'           => 'Three generations of goat farming.',
            ]);
    }
}
