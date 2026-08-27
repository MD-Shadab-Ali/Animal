<?php

namespace App\Services;

use App\Models\EmailOtp;
use App\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Issuing and spending the codes that prove an address is real.
 *
 * Every rule about a code lives here rather than in the routes that use it, so
 * signup and password reset cannot end up enforcing different things. A code
 * is single use, expires, is rate limited on the way out and limited on the
 * way in -- and is only ever stored hashed.
 */
class OtpService
{
    /**
     * Send a fresh code, replacing whatever was outstanding.
     *
     * Replacing rather than adding is the point: two live codes for one
     * address would double the guessing surface and leave the older one valid
     * after its owner has moved on to the newer.
     */
    public function issue(string $email, string $purpose): EmailOtp
    {
        $email = mb_strtolower(trim($email));

        $existing = EmailOtp::where('email', $email)->where('purpose', $purpose)->first();

        // Asking again immediately is either an impatient person or someone
        // using the form to post mail to an address they do not own.
        if ($existing && ($wait = $existing->resendCooldown()) > 0) {
            throw ValidationException::withMessages([
                'email' => ['Please wait '.$wait.' seconds before asking for another code.'],
            ]);
        }

        $code = $this->generateCode();

        $otp = EmailOtp::updateOrCreate(
            ['email' => $email, 'purpose' => $purpose],
            [
                'code_hash'    => Hash::make($code),
                'attempts'     => 0,
                'expires_at'   => now()->addMinutes(EmailOtp::TTL_MINUTES),
                'consumed_at'  => null,
                'last_sent_at' => now(),
            ]
        );

        // Routed to the address rather than to a user: at signup there is no
        // user yet, and proving the address is what decides whether to make one.
        Notification::route('mail', $email)
            ->notify(new EmailOtpNotification($code, $purpose));

        return $otp;
    }

    /**
     * Spend a code, or explain why it cannot be spent.
     *
     * A wrong guess costs an attempt; a right one consumes the code outright,
     * so the same code can never be used twice.
     */
    public function verify(string $email, string $purpose, string $code): void
    {
        $email = mb_strtolower(trim($email));

        $otp = EmailOtp::where('email', $email)->where('purpose', $purpose)->first();

        if (! $otp || $otp->isSpent()) {
            throw ValidationException::withMessages([
                'code' => ['That code is not valid any more. Ask for a new one.'],
            ]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            $left = EmailOtp::MAX_ATTEMPTS - $otp->attempts;

            throw ValidationException::withMessages([
                'code' => [$left > 0
                    ? 'That code is not right. '.$left.' '.($left === 1 ? 'try' : 'tries').' left.'
                    : 'Too many wrong tries. Ask for a new code.'],
            ]);
        }

        $otp->forceFill(['consumed_at' => now()])->save();
    }

    /** Clear anything outstanding for an address, once it no longer matters. */
    public function forget(string $email, string $purpose): void
    {
        EmailOtp::where('email', mb_strtolower(trim($email)))
            ->where('purpose', $purpose)
            ->delete();
    }

    /**
     * A code with no pattern to it.
     *
     * random_int is the cryptographic one; rand() and mt_rand() are guessable
     * from a handful of samples, which is exactly what someone collecting
     * codes for their own address would have.
     */
    private function generateCode(): string
    {
        $max = (10 ** EmailOtp::LENGTH) - 1;

        return str_pad((string) random_int(0, $max), EmailOtp::LENGTH, '0', STR_PAD_LEFT);
    }
}
