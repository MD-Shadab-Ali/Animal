<?php

namespace Tests\Feature;

use App\Filament\Resources\HomeSections\Pages\EditHomeSection;
use App\Models\HomeSection;
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
}
