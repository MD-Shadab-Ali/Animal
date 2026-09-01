<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Goat;
use App\Models\GoatWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Facts about one goat, kept on that goat.
 *
 * A listing used to carry a single age, tooth count and colour, which worked
 * only while a listing was one animal. It is not: behind one listing are
 * fifteen goats between 20 and 60 kg, and no single reading is true of all of
 * them -- dentition least of all, since it is how a goat's age is read.
 */
class AnimalDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $pool = []): Goat
    {
        $goat = Goat::create([
            'category_id' => Category::create(['name' => 'Pen', 'slug' => 'pen'])->id,
            'name' => 'Pen Buck',
            'slug' => 'pen-buck',
            'gender' => 'male',
            'breed' => 'Khari',
            'price' => 5000,
            'weight_kg' => 50,
            'age_months' => 10,
            'teeth' => 4,
            'color' => 'Golden',
            'min_weight_kg' => 20,
            'max_weight_kg' => 60,
            'weight_step_kg' => 1,
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

    /** Each animal keeps its own age, whatever the listing says. */
    public function test_animals_hold_their_own_age_and_teeth(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 20, 'age_months' => 8, 'teeth' => 0],
            ['weight_kg' => 60, 'age_months' => 40, 'teeth' => 8],
        ]);

        $young = $goat->weights->firstWhere('weight_kg', 20);
        $old = $goat->weights->firstWhere('weight_kg', 60);

        $this->assertSame(8, $young->age_months);
        $this->assertSame(40, $old->age_months);
        // The listing's own figure is now nobody's fact but its own.
        $this->assertNotSame($goat->age_months, $old->age_months);
    }

    /** A tooth count means an age; printing the bare number says nothing. */
    public function test_teeth_are_read_back_as_an_age(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 20, 'teeth' => 0],
            ['weight_kg' => 30, 'teeth' => 4],
            ['weight_kg' => 40, 'teeth' => 8],
        ]);

        $this->assertStringContainsString('under a year', $goat->weights->firstWhere('teeth', 0)->teethLabel());
        $this->assertStringContainsString('2 years', $goat->weights->firstWhere('teeth', 4)->teethLabel());
        $this->assertStringContainsString('Full mouth', $goat->weights->firstWhere('teeth', 8)->teethLabel());
    }

    /**
     * Unrecorded is its own answer.
     *
     * Null has to survive as null: an animal nobody has vaccinated yet is not
     * an animal recorded as unvaccinated, and a reading nobody has taken is
     * not a reading of zero.
     */
    public function test_an_unrecorded_reading_stays_unrecorded(): void
    {
        $goat = $this->listing([['weight_kg' => 20]]);
        $animal = $goat->weights->first();

        $this->assertNull($animal->age_months);
        $this->assertNull($animal->teeth);
        $this->assertNull($animal->teethLabel());
        $this->assertNull($animal->is_vaccinated);
        $this->assertNull($animal->vaccinationLabel());
    }

    public function test_vaccination_distinguishes_no_from_not_recorded(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 20, 'is_vaccinated' => true],
            ['weight_kg' => 30, 'is_vaccinated' => false],
            ['weight_kg' => 40],
        ]);

        $this->assertSame('Vaccinated', $goat->weights->firstWhere('weight_kg', 20)->vaccinationLabel());
        $this->assertSame('Not vaccinated', $goat->weights->firstWhere('weight_kg', 30)->vaccinationLabel());
        $this->assertNull($goat->weights->firstWhere('weight_kg', 40)->vaccinationLabel());
    }

    /** The scanned tag answers with this animal's record, not the listing's. */
    public function test_the_scanned_page_returns_the_animals_own_facts(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 22, 'age_months' => 9, 'teeth' => 0, 'color' => 'Grey', 'tag' => 'PB-22'],
        ]);
        $animal = $goat->weights->first();

        $data = $this->getJson('/api/v1/animals/'.$animal->token)->assertOk()->json('data');

        $this->assertSame(9, $data['age_months']);
        $this->assertSame(0, $data['teeth']);
        $this->assertStringContainsString('under a year', $data['teeth_label']);
        $this->assertSame('Grey', $data['color']);
        $this->assertSame('Khari', $data['listing']['breed']);
        // Not the listing's 10 months, 4 teeth, Golden.
        $this->assertNotSame($goat->age_months, $data['age_months']);
        $this->assertNotSame($goat->color, $data['color']);
    }

    /** A listing describes the group by its range, not by one member. */
    public function test_the_listing_summarises_the_pool_as_ranges(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 20, 'age_months' => 8],
            ['weight_kg' => 45, 'age_months' => 26],
            ['weight_kg' => 60, 'age_months' => 40, 'status' => 'sold'],
        ]);

        $summary = $goat->poolSummary();

        // The sold one is out: it is not on offer.
        $this->assertSame(2, $summary['count']);
        $this->assertSame(20.0, $summary['min_weight_kg']);
        $this->assertSame(45.0, $summary['max_weight_kg']);
        $this->assertSame(8, $summary['min_age_months']);
        $this->assertSame(26, $summary['max_age_months']);
    }

    /** No ages recorded is not an age range of nothing. */
    public function test_a_pool_with_no_ages_reports_no_age_range(): void
    {
        $summary = $this->listing([['weight_kg' => 20], ['weight_kg' => 40]])->poolSummary();

        $this->assertSame(2, $summary['count']);
        $this->assertNull($summary['min_age_months']);
        $this->assertNull($summary['max_age_months']);
    }

    /** A listing keeping no animals still describes itself as it always did. */
    public function test_a_listing_without_a_pool_has_no_summary(): void
    {
        $this->assertNull($this->listing()->poolSummary());
    }

    /** The goat page is told about the pool so it can stop guessing. */
    public function test_the_goat_endpoint_sends_the_pool_summary(): void
    {
        $goat = $this->listing([
            ['weight_kg' => 20, 'age_months' => 8],
            ['weight_kg' => 50, 'age_months' => 30],
        ]);

        $pool = $this->getJson('/api/v1/goats/'.$goat->slug)->assertOk()->json('data.pool');

        $this->assertSame(2, $pool['count']);
        $this->assertSame(8, $pool['min_age_months']);
        $this->assertSame(30, $pool['max_age_months']);
    }
}
