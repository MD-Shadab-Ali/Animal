<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserEditTest extends TestCase
{
    use RefreshDatabase;

    /** An admin must be able to edit a customer without resetting their password. */
    public function test_editing_a_customer_without_touching_the_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $customer = User::where('role', 'customer')->firstOrFail();
        $originalHash = $customer->password;

        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['name' => 'Renamed Customer'])
            ->call('save')
            ->assertHasNoFormErrors();

        $customer->refresh();

        $this->assertSame('Renamed Customer', $customer->name);
        $this->assertSame($originalHash, $customer->password, 'The password hash changed on an unrelated edit');
    }
}
