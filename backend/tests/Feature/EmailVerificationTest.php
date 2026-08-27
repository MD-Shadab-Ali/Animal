<?php

namespace Tests\Feature;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Services\OtpService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Proving an address before an account can be used.
 *
 * Signing up with an address you cannot read used to be as good as signing up
 * with your own, which is the whole reason for the code.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function signUp(string $email = 'newbuyer@example.test'): array
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'New Buyer',
            'email'                 => $email,
            'phone'                 => '+977 9800-123456',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $code = null;

        Notification::assertSentOnDemand(EmailOtpNotification::class,
            function (EmailOtpNotification $notification) use (&$code) {
                $code = $notification->code;

                return $notification->purpose === EmailOtp::PURPOSE_REGISTER;
            });

        return [$response, $code];
    }

    public function test_registering_hands_back_no_token_until_the_code_is_entered(): void
    {
        [$response] = $this->signUp();

        // The account exists, so the address is taken and the details are
        // kept -- but nothing that could be used to act as that person.
        $response->assertJsonPath('data.verification_required', true)
            ->assertJsonMissingPath('data.token');

        $user = User::where('email', 'newbuyer@example.test')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_unverified_account_cannot_sign_in(): void
    {
        $this->signUp();

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'newbuyer@example.test',
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_right_code_finishes_the_signup(): void
    {
        [, $code] = $this->signUp();

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'newbuyer@example.test',
            'code'  => $code,
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'newbuyer@example.test')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertNotNull(User::where('email', 'newbuyer@example.test')->firstOrFail()->email_verified_at);
    }

    public function test_a_code_cannot_be_spent_twice(): void
    {
        [, $code] = $this->signUp();

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'newbuyer@example.test', 'code' => $code,
        ])->assertCreated();

        // Already verified, so there is nothing left for the code to do.
        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'newbuyer@example.test', 'code' => $code,
        ])->assertStatus(422);
    }

    public function test_guessing_is_limited(): void
    {
        [, $code] = $this->signUp();

        $otps = app(OtpService::class);

        // Driven through the service rather than the route: the auth throttle
        // cuts in at five requests a minute and would answer 429 before the
        // attempt cap was ever reached, which tests the wrong guard.
        for ($try = 1; $try <= EmailOtp::MAX_ATTEMPTS; $try++) {
            try {
                $otps->verify('newbuyer@example.test', EmailOtp::PURPOSE_REGISTER, '000000');
                $this->fail('A wrong code should not verify.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors());
            }
        }

        $otp = EmailOtp::where('email', 'newbuyer@example.test')->firstOrFail();

        $this->assertTrue($otp->isExhausted());
        $this->assertTrue($otp->isSpent());

        // Exhausted, so even the real code is no longer worth anything.
        $this->expectException(ValidationException::class);
        $otps->verify('newbuyer@example.test', EmailOtp::PURPOSE_REGISTER, $code);
    }

    public function test_an_expired_code_is_refused(): void
    {
        [, $code] = $this->signUp();

        EmailOtp::where('email', 'newbuyer@example.test')
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'newbuyer@example.test', 'code' => $code,
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_code_is_never_stored_in_the_clear(): void
    {
        [, $code] = $this->signUp();

        $otp = EmailOtp::where('email', 'newbuyer@example.test')->firstOrFail();

        // A leaked table would otherwise hand over every live code.
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
    }

    public function test_asking_again_straight_away_is_refused(): void
    {
        $this->signUp();

        // The send button is not a way to post mail to someone else's inbox.
        $this->postJson('/api/v1/auth/resend-verification', [
            'email' => 'newbuyer@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_verified_account_signs_in_as_normal(): void
    {
        [, $code] = $this->signUp();

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'newbuyer@example.test', 'code' => $code,
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'newbuyer@example.test',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }
}
