<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A short-lived code emailed to prove an address is real.
 *
 * The row holds only a hash of the code. Everything about spending one --
 * expiry, attempts, single use -- is decided here rather than by the caller,
 * so no route can accidentally accept a code the rules would have refused.
 */
class EmailOtp extends Model
{
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /** Long enough not to be guessed, short enough to read off a phone. */
    public const LENGTH = 6;

    public const TTL_MINUTES = 10;

    /** A six-digit code has a million combinations; five tries is nowhere near. */
    public const MAX_ATTEMPTS = 5;

    /** Stops the send button being used as a way to flood someone's inbox. */
    public const RESEND_SECONDS = 60;

    protected $fillable = [
        'email', 'purpose', 'code_hash', 'attempts',
        'expires_at', 'consumed_at', 'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'   => 'datetime',
            'consumed_at'  => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    /** Nothing left to try: expired, already spent, or guessed at too often. */
    public function isSpent(): bool
    {
        return $this->isExpired() || $this->isConsumed() || $this->isExhausted();
    }

    /** Seconds still to wait before another code may be sent. */
    public function resendCooldown(): int
    {
        if (! $this->last_sent_at) {
            return 0;
        }

        $elapsed = (int) $this->last_sent_at->diffInSeconds(now());

        return (int) max(0, self::RESEND_SECONDS - $elapsed);
    }
}
