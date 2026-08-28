<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A staff account signing in on the storefront rather than the panel.
 *
 * They are the same account either way, so the storefront has to be told who
 * it is talking to -- enough to offer the way back to the panel, and to keep
 * them out of the seller application they would otherwise be judging on both
 * sides. Disabling an account has to end its storefront session too, which is
 * what the token in localStorage made easy to miss.
 */
class StaffOnStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::create([
            'name'      => $role->label().' Person',
            'email'     => $role->value.'@storefront.test',
            'phone'     => '+880 1700-000009',
            'password'  => 'password',
            'role'      => $role,
            'is_active' => true,
        ]);

        // Not a fillable attribute -- mass assignment drops it silently, and the
        // account is then unable to sign in at all.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function signIn(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    public function test_login_tells_the_storefront_the_role(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $admin->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.is_staff', true);
    }

    public function test_a_customer_is_not_staff(): void
    {
        $customer = User::where('role', UserRole::Customer)->firstOrFail();

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.is_staff', false);
    }

    public function test_a_token_stops_working_once_the_account_is_disabled(): void
    {
        $customer = User::where('role', UserRole::Customer)->firstOrFail();
        $token = $this->signIn($customer);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        // Straight to the database, so the observer's revocation is not what is
        // under test here -- this is the middleware standing on its own.
        User::withoutEvents(fn () => $customer->forceFill(['is_active' => false])->save());

        // Every request in one test method shares a container, and the guard
        // holds on to the user it already resolved. A real second request comes
        // to a fresh application and would look the account up again.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'account_disabled');
    }

    public function test_disabling_an_account_revokes_its_tokens(): void
    {
        $customer = User::where('role', UserRole::Customer)->firstOrFail();
        $this->signIn($customer);

        $this->assertSame(1, $customer->tokens()->count());

        // What saving the toggle in the panel does.
        $customer->update(['is_active' => false]);

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_changing_a_role_revokes_its_tokens(): void
    {
        $customer = User::where('role', UserRole::Customer)->firstOrFail();
        $this->signIn($customer);

        $this->assertSame(1, $customer->tokens()->count());

        $customer->update(['role' => UserRole::Staff]);

        // The storefront caches the role it was handed at sign-in; signing in
        // again is what refreshes it.
        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_re_enabling_an_account_leaves_its_tokens_alone(): void
    {
        $customer = User::where('role', UserRole::Customer)->firstOrFail();
        $token = $this->signIn($customer);

        $customer->update(['is_active' => false]);
        $customer->update(['is_active' => true]);

        // Turning someone back on must not be a way to hand them a live session
        // they never asked for -- the disable already took the tokens.
        $this->assertSame(0, $customer->tokens()->count());
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_staff_cannot_apply_to_sell(): void
    {
        Storage::fake('public');

        $manager = $this->userWithRole(UserRole::Manager);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/seller/apply', [
            'farm_name'     => 'Conflict Of Interest Farm',
            'contact_phone' => '+880 1700-000009',
            'city'          => 'Khulna',
            'national_id'   => '1990777888999',
            'id_document'   => UploadedFile::fake()->image('nid.jpg'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('farm_name');

        $this->assertDatabaseMissing('sellers', ['user_id' => $manager->id]);
    }

    public function test_a_customer_can_still_apply_to_sell(): void
    {
        Storage::fake('public');

        $customer = User::create([
            'name' => 'Keen Seller', 'email' => 'keen@example.test',
            'phone' => '+880 1700-000010', 'password' => 'password',
            'role' => UserRole::Customer, 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/seller/apply', [
            'farm_name'     => 'Keen Farm',
            'contact_phone' => '+880 1700-000010',
            'city'          => 'Khulna',
            'national_id'   => '1990777888777',
            'id_document'   => UploadedFile::fake()->image('nid.jpg'),
        ])->assertCreated();

        $this->assertDatabaseHas('sellers', ['user_id' => $customer->id]);
    }
}
