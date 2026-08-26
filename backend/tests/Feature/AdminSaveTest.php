<?php

namespace Tests\Feature;

use App\Filament\Resources\HomeSections\Pages\EditHomeSection;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Models\HomeSection;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSaveTest extends TestCase
{
    use RefreshDatabase;

    /** Saving a section without touching it must not destroy its config JSON. */
    public function test_saving_a_home_section_preserves_its_config(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $section = HomeSection::where('type', 'featured_goats')->firstOrFail();
        $before = $section->config;

        $this->assertIsArray($before, 'Seeded config should be an array');
        $this->assertSame(8, $before['limit']);

        Livewire::test(EditHomeSection::class, ['record' => $section->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $section->fresh()->config;

        $this->assertIsArray($after, 'config stopped being an array after an admin save');
        $this->assertSame($before, $after, 'config was altered by a no-op save');
    }

    /**
     * How long a refund takes is data, not a constant in the code.
     *
     * It was hardcoded as "a day or two" for every rail, which is wrong for a
     * wallet. It has to be something an admin can correct without a deploy.
     */
    public function test_an_admin_can_change_how_long_refunds_take_on_a_method(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $method = PaymentMethod::where('code', 'esewa')->firstOrFail();

        $this->assertSame('straight away', $method->refund_eta);

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->assertFormFieldExists('refund_eta')
            ->fillForm(['refund_eta' => 'within a few hours'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('within a few hours', $method->fresh()->refund_eta);

        // And the change is what the buyer is told.
        $payment = new Payment(['method' => 'esewa']);

        $this->assertSame('within a few hours', $payment->arrival_eta);
    }

    /** Cleared means we say nothing, rather than falling back to a guess. */
    public function test_clearing_it_makes_the_shop_promise_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $method = PaymentMethod::where('code', 'esewa')->firstOrFail();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
            ->fillForm(['refund_eta' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($method->fresh()->refund_eta);
        $this->assertNull((new Payment(['method' => 'esewa']))->arrival_eta);
    }
}
