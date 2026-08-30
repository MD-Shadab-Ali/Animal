<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use App\Services\RecaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The robot check on the password forms, and the one flow it must stay off.
 *
 * The checkbox is only worth anything because the token behind it is checked
 * with Google here rather than trusted from the browser. Google sign-in matters
 * because it has to be the same door for a new customer and a returning one --
 * and because it must not ask for a second robot check of its own.
 */
class AuthRecaptchaAndGoogleTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'New Buyer',
            'email'                 => 'newbuyer@example.test',
            'phone'                 => '+977 9800-123456',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    /** Puts the real verifier back, with Google's reply under our control. */
    private function realRecaptcha(array $googleReply): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);

        $this->swap(RecaptchaVerifier::class, new RecaptchaVerifier);

        Http::fake(['www.google.com/recaptcha/api/siteverify' => Http::response($googleReply)]);
    }

    public function test_signing_up_without_ticking_the_box_is_refused(): void
    {
        $this->realRecaptcha(['success' => true]);

        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertStatus(422)
            ->assertJsonValidationErrors('recaptcha');

        $this->assertDatabaseMissing('users', ['email' => 'newbuyer@example.test']);

        // Refused before Google was troubled: there is nothing to ask about.
        Http::assertNothingSent();
    }

    public function test_a_token_google_rejects_is_refused(): void
    {
        $this->realRecaptcha(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->postJson('/api/v1/auth/register', $this->registration([
            'recaptcha_token' => 'a-token-google-does-not-like',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('recaptcha');

        $this->assertDatabaseMissing('users', ['email' => 'newbuyer@example.test']);
    }

    /** A token is good for about two minutes and for one use. */
    public function test_an_expired_token_says_so_in_words(): void
    {
        $this->realRecaptcha(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $this->postJson('/api/v1/auth/register', $this->registration([
            'recaptcha_token' => 'a-stale-token',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.recaptcha.0', 'That robot check expired. Please tick the box again.');
    }

    public function test_a_token_google_accepts_lets_the_signup_through(): void
    {
        Notification::fake();

        $this->realRecaptcha(['success' => true]);

        $this->postJson('/api/v1/auth/register', $this->registration([
            'recaptcha_token' => 'a-good-token',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'newbuyer@example.test']);
    }

    public function test_signing_in_needs_the_box_too(): void
    {
        $user = User::factory()->create([
            'email'             => 'buyer@example.test',
            'password'          => 'password123',
            'email_verified_at' => now(),
        ]);

        $this->realRecaptcha(['success' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recaptcha');

        $this->postJson('/api/v1/auth/login', [
            'email'           => $user->email,
            'password'        => 'password123',
            'recaptcha_token' => 'a-good-token',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
    }

    /**
     * The one flow the checkbox must stay off.
     *
     * Signing in through Google has already proved there is a person there, so
     * asking again would be friction for nothing.
     */
    public function test_google_sign_in_is_not_asked_for_a_robot_check(): void
    {
        $this->realRecaptcha(['success' => false, 'error-codes' => ['missing-input-response']]);

        $this->stubGoogle('google-subject-9', 'no-robot-check@example.test');

        $this->postJson('/api/v1/auth/google', ['credential' => 'stubbed'])->assertCreated();

        Http::assertNothingSent();
    }

    /** Nobody should have to know whether they are registering or signing in. */
    public function test_google_creates_an_account_and_then_signs_the_same_one_in(): void
    {
        $this->stubGoogle('google-subject-1', 'gmail-person@example.test');

        $first = $this->postJson('/api/v1/auth/google', ['credential' => 'stubbed'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', [
            'email'     => 'gmail-person@example.test',
            'google_id' => 'google-subject-1',
        ]);

        // Created through Google, so the address is already proved and there is
        // no emailed code standing between them and their account.
        $user = User::where('email', 'gmail-person@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);

        $second = $this->postJson('/api/v1/auth/google', ['credential' => 'stubbed'])
            ->assertOk();

        $this->assertSame(1, User::where('email', 'gmail-person@example.test')->count());
        $this->assertNotSame($first->json('data.token'), $second->json('data.token'));
    }

    /** An address already here with a password is linked, not duplicated. */
    public function test_google_signs_in_an_existing_password_account(): void
    {
        $existing = User::factory()->create([
            'email'             => 'both-ways@example.test',
            'password'          => 'password123',
            'email_verified_at' => now(),
        ]);

        $this->stubGoogle('google-subject-2', 'both-ways@example.test');

        $this->postJson('/api/v1/auth/google', ['credential' => 'stubbed'])->assertOk();

        $this->assertSame(1, User::where('email', 'both-ways@example.test')->count());
        $this->assertSame('google-subject-2', $existing->fresh()->google_id);

        // Their password still works, and still needs the same captcha rules.
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'both-ways@example.test',
            'password' => 'password123',
        ])->assertOk();
    }

    /** Stands in for Google, so the suite never leaves the machine. */
    private function stubGoogle(string $subject, string $email): void
    {
        $this->instance(GoogleIdTokenVerifier::class, new class($subject, $email) extends GoogleIdTokenVerifier
        {
            public function __construct(private string $subject, private string $email) {}

            public function verify(string $credential): array
            {
                return ['sub' => $this->subject, 'email' => $this->email, 'name' => 'Gmail Person'];
            }
        });
    }
}
