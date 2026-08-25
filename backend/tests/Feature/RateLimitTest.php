<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        RateLimiter::clear('auth-email:'.mb_strtolower('customer@example.test'));
    }

    public function test_repeated_bad_logins_are_locked_out(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();

        $attempt = fn () => $this->postJson('/api/v1/auth/login', [
            'email'    => $customer->email,
            'password' => 'definitely-wrong',
        ]);

        // The limiter allows five tries a minute for a given email.
        for ($i = 0; $i < 5; $i++) {
            $attempt()->assertStatus(422);
        }

        $attempt()->assertStatus(429);
    }

    public function test_the_public_contact_form_is_throttled(): void
    {
        $payload = [
            'name'    => 'Spammer',
            'message' => 'Buy my thing',
        ];

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/contact', $payload)->assertCreated();
        }

        $this->postJson('/api/v1/contact', $payload)->assertStatus(429);
    }

    public function test_normal_browsing_is_not_throttled(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/goats')->assertOk();
        }
    }
}
