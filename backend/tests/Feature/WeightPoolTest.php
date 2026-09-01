<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Goat;
use App\Models\GoatWeight;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The real animals recorded against a listing.
 *
 * Nothing on the storefront reads these at the moment: the buyer's selector
 * went back to describing a range and pricing it by arithmetic. What is kept
 * here is the part that has to stay true for the data to be worth anything --
 * that a request can be matched to the nearest real animal, that the price
 * follows that animal, and that selling one takes it out of the reckoning.
 */
class WeightPoolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function listing(array $poolWeights = []): Goat
    {
        $goat = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Pooled Buck',
            'gender' => 'male',
            'price' => 5000,
            'weight_kg' => 50,
            'min_weight_kg' => 20,
            'max_weight_kg' => 60,
            'weight_step_kg' => 1,
            'stock' => 6,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        foreach ($poolWeights as $kg) {
            GoatWeight::create(['goat_id' => $goat->id, 'weight_kg' => $kg]);
        }

        return $goat->fresh();
    }

    public function test_a_listing_without_animals_has_nothing_to_match(): void
    {
        $goat = $this->listing();

        $this->assertFalse($goat->hasWeightPool());
        $this->assertNull($goat->nearestWeight(45));
        // The declared range is untouched by any of this.
        $this->assertSame(20.0, $goat->lightest_weight);
        $this->assertSame(60.0, $goat->heaviest_weight);
    }

    public function test_a_request_lands_on_the_nearest_animal_either_side(): void
    {
        $goat = $this->listing([44, 47, 50, 53]);

        $this->assertSame(44.0, (float) $goat->nearestWeight(45)->weight_kg, 'nearest below');
        $this->assertSame(47.0, (float) $goat->nearestWeight(46)->weight_kg, 'nearest above');
        $this->assertSame(50.0, (float) $goat->nearestWeight(50)->weight_kg, 'exact match');
        $this->assertSame(44.0, (float) $goat->nearestWeight(10)->weight_kg, 'below everything');
        $this->assertSame(53.0, (float) $goat->nearestWeight(99)->weight_kg, 'above everything');
    }

    /** Equidistant: the cheaper animal is the one to hand over unasked. */
    public function test_a_tie_goes_to_the_lighter_animal(): void
    {
        $goat = $this->listing([44, 46]);

        $this->assertSame(44.0, (float) $goat->nearestWeight(45)->weight_kg);
    }

    public function test_the_price_follows_the_animal_that_was_matched(): void
    {
        $goat = $this->listing([44, 47]);

        // Rs 5,000 at 50 kg is Rs 100/kg.
        $matched = $goat->nearestWeight(45);

        $this->assertSame(44.0, (float) $matched->weight_kg);
        $this->assertSame(4400.0, $matched->price());
    }

    public function test_a_sold_animal_leaves_the_pool(): void
    {
        $goat = $this->listing([44, 47, 50]);

        $goat->weights()->where('weight_kg', 44)->first()->markSold();
        $goat = $goat->fresh();

        $this->assertCount(2, $goat->availableWeights());
        // 45 used to land on 44; with it gone the next nearest is 47.
        $this->assertSame(47.0, (float) $goat->nearestWeight(45)->weight_kg);
    }

    /*
    |--------------------------------------------------------------------------
    | The scanned ear tag
    |--------------------------------------------------------------------------
    */

    public function test_every_animal_is_given_a_token_of_its_own(): void
    {
        $goat = $this->listing([44, 47]);

        $tokens = $goat->weights->pluck('token');

        $this->assertCount(2, $tokens->filter());
        $this->assertCount(2, $tokens->unique(), 'two animals must not share an address');
    }

    public function test_scanning_a_tag_shows_that_animal(): void
    {
        $goat = $this->listing([44]);
        $animal = $goat->weights->first();

        $body = $this->getJson('/api/v1/animals/'.$animal->token)
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Available')
            ->assertJsonPath('data.listing.name', 'Pooled Buck')
            ->json('data');

        // Compared numerically: json_encode writes the float 44.00 as 44.
        $this->assertEqualsWithDelta(44, $body['weight_kg'], 0.001);
        $this->assertEqualsWithDelta(4400, $body['price'], 0.001);
    }

    /** A sold animal has to say so, or the tag misleads whoever scanned it. */
    public function test_a_sold_animal_reads_as_sold_when_scanned(): void
    {
        $goat = $this->listing([44]);
        $animal = $goat->weights->first();
        $animal->markSold();

        $this->getJson('/api/v1/animals/'.$animal->token)
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Sold');
    }

    /**
     * The pen cannot be read by counting upwards.
     *
     * The address is a random token precisely so that a page anyone may open
     * does not double as a way of enumerating the stock.
     */
    public function test_an_animal_cannot_be_reached_by_its_row_id(): void
    {
        $goat = $this->listing([44]);
        $animal = $goat->weights->first();

        $this->getJson('/api/v1/animals/'.$animal->id)->assertNotFound();
        $this->getJson('/api/v1/animals/'.Str::random(32))->assertNotFound();
    }

    public function test_selling_every_animal_empties_the_pool(): void
    {
        $goat = $this->listing([44, 47]);

        $goat->weights->each->markSold();
        $goat = $goat->fresh();

        $this->assertFalse($goat->hasWeightPool());
        $this->assertNull($goat->nearestWeight(45));
    }
}
