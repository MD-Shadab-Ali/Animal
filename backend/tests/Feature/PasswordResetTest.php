<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_reset_link_is_emailed_and_points_at_the_storefront(): void
    {
        Notification::fake();

        $customer = User::where('role', 'customer')->firstOrFail();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $customer->email])
            ->assertOk();

        Notification::assertSentTo($customer, ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($customer) {
                $mail = $notification->toMail($customer);
                $url = $mail->actionUrl;

                return str_starts_with($url, config('app.frontend_url').'/reset-password')
                    && str_contains($url, 'token=')
                    && str_contains($url, urlencode($customer->email));
            });
    }

    public function test_an_unknown_email_does_not_reveal_whether_the_account_exists(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email belongs to an account, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_a_customer_can_reset_their_password_and_old_tokens_die(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        $oldToken = $customer->createToken('storefront')->plainTextToken;

        $token = \Illuminate\Support\Facades\Password::createToken($customer);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $customer->email,
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $customer->refresh();

        $this->assertTrue(Hash::check('a-brand-new-password', $customer->password));
        $this->assertSame(0, $customer->tokens()->count(), 'Existing API tokens should be revoked');

        // The old bearer token must no longer work.
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // And the new password signs in.
        $this->postJson('/api/v1/auth/login', [
            'email'    => $customer->email,
            'password' => 'a-brand-new-password',
        ])->assertOk();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => 'not-a-real-token',
            'email'                 => $customer->email,
            'password'              => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
