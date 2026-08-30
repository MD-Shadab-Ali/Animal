<?php

namespace App\Services\Gateways;

/**
 * What a provider says about one payment attempt, in our words.
 *
 * eSewa answers COMPLETE/PENDING/NOT_FOUND, Khalti answers Completed/Expired/
 * "User canceled". Nothing above this class should have to know that.
 */
final class GatewayResult
{
    /** Money has arrived and is ours to keep. */
    public const PAID = 'paid';

    /** Started, not finished. Ask again later; never confirm on this. */
    public const PENDING = 'pending';

    /** Cancelled, expired or refused. The attempt is over. */
    public const FAILED = 'failed';

    /** The provider has no record. Usually an attempt the buyer abandoned. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        /** In rupees, as the provider reports it -- Khalti's paisa are converted. */
        public readonly ?float $amount = null,
        /** The provider's own transaction id, once there is one. */
        public readonly ?string $transactionId = null,
        /** Their status string, unaltered, for the audit trail. */
        public readonly ?string $rawStatus = null,
        public readonly array $payload = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
