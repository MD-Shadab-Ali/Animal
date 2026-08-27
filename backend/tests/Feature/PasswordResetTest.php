<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\EmailOtp;
use App\Notifications\EmailOtpNotification;
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

    /** Pulls the code out of the email the way a person reads it off theirs. */
    private function codeFor(User $customer): string
    {
        $code = null;

        Notification::assertSentOnDemand(EmailOtpNotification::class,
            function (EmailOtpNotification $notification) use (&$code) {
                $code = $notification->code;

                return $notification->purpose === EmailOtp::PURPOSE_PASSWORD_RESET;
            });

        return $code;
    }

    public function test_a_reset_code_is_emailed(): void
    {
        Notification::fake();

        $customer = User::where('role', 'customer')->firstOrFail();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $customer->email])
            ->assertOk();

        // A code rather than a link: nothing to click, so nothing for a
        // phishing mail to imitate, and it cannot be spent by anyone who
        // merely saw the message go past.
        $code = $this->codeFor($customer);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_an_unknown_email_does_not_reveal_whether_the_account_exists(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email belongs to an account, a reset code is on its way.');

        Notification::assertNothingSent();
    }

    public function test_a_customer_can_reset_their_password_and_old_tokens_die(): void
    {
        Notification::fake();

        $customer = User::where('role', 'customer')->firstOrFail();
        $oldToken = $customer->createToken('storefront')->plainTextToken;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $customer->email])
            ->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'code'                  => $this->codeFor($customer),
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

    public function test_a_wrong_code_is_rejected(): void
    {
        Notification::fake();

        $customer = User::where('role', 'customer')->firstOrFail();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $customer->email])
            ->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'code'                  => '000000',
            'email'                 => $customer->email,
            'password'              => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // And the password is untouched by a failed attempt.
        $this->assertFalse(Hash::check('another-password', $customer->fresh()->password));
    }
}
