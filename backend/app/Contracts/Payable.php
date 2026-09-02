<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something money can be taken for.
 *
 * There are two of these now -- an order of goats and a booked room -- and
 * there was a strong temptation to give the second one its own service, its own
 * ledger and its own advance arithmetic. That temptation is the whole reason
 * this interface exists.
 *
 * PaymentService is not a helper that orders happen to use. It is where a claim
 * becomes a receipt, where an advance is decided, where a refund is filed, and
 * where a gateway's answer is turned into money on the books. Written twice,
 * those two copies disagree eventually, and the way anybody finds out is a
 * guest charged twice or a room held for money that never arrived.
 *
 * So the service goes on working exactly as it did and stops caring what it is
 * taking money for. Everything below is the part that genuinely differs between
 * an order and a booking -- what "already settled" means, what a payment
 * unlocks -- and nothing else.
 *
 * Every implementer is an Eloquent model. `fresh()` and `getKey()` are declared
 * here because the service leans on both, and their signatures are copied
 * exactly from Model, so an implementer satisfies them without writing a line.
 */
interface Payable
{
    /** The ledger rows against this thing. */
    public function payments(): HasMany;

    /** The column on `payments` that points back here. */
    public function paymentForeignKey(): string;

    /** Who the money comes from, and who hears when it lands. */
    public function payer(): ?User;

    /** What it costs altogether, which is what "paid in full" is measured against. */
    public function paymentTotal(): float;

    /** What is still owed on the whole thing. */
    public function balanceDue(): float;

    /**
     * What is owed *right now*.
     *
     * Not the same as the balance on an advance plan, where the rest is not due
     * until the goat is at the door or the guest is at the gate. This is the
     * figure a pay button should open for, so the buyer is never asked for
     * money they were told they could pay later.
     */
    public function amountDueNow(): float;

    public function paymentCurrency(): string;

    /** What it was placed on, used when a payment does not name a method. */
    public function defaultPaymentMethod(): ?string;

    /** Called off, so no more money should be taken for it. */
    public function isCancelled(): bool;

    public function isFullyPaid(): bool;

    public function isRefundable(): bool;

    public function refundableAmount(): float;

    /**
     * A stem for a gateway's reference to an attempt on this.
     *
     * The provider quotes it back at us and a person may end up reading it down
     * a phone line, so it is the order or booking number rather than an id.
     */
    public function paymentReference(): string;

    /** How the refusal reads when somebody tries to pay a cancelled one. */
    public function cancelledMessage(): string;

    /** How the refusal reads when there is nothing left to pay. */
    public function settledMessage(): string;

    /*
    |--------------------------------------------------------------------------
    | Saying what this is, to a person
    |--------------------------------------------------------------------------
    |
    | The four payment notifications used to open with `$payment->order` and
    | build every sentence from it. Pointed at a booking that is a fatal error
    | on a null, in a queued job, after the money has already been taken -- the
    | worst possible place to find out. So the sentences are assembled from
    | these instead, and neither the mail nor the service has to know which of
    | the two it is holding.
    |
    */

    /** 'order' or 'booking', to be dropped into a sentence about it. */
    public function paymentSubjectNoun(): string;

    /** Where on the storefront the payer can see it. */
    public function paymentSubjectPath(): string;

    /** Whose money this is, in words, for a note to staff. */
    public function payerName(): string;

    /*
     * How to reach whoever is paying.
     *
     * Taken from the order or the booking rather than from the account,
     * because both snapshot the contact details they were placed with and a
     * gateway wants the details that belong to *this* transaction. Either can
     * be missing, and a gateway that is handed a blank simply omits it.
     */
    public function payerEmail(): ?string;

    public function payerPhone(): ?string;

    /**
     * What the money is for, a line at a time.
     *
     * The goats on an order; the room and the dates on a booking. Staff should
     * be able to tell what a payment is against without opening it.
     *
     * @return list<string>
     */
    public function paymentSummaryLines(): array;

    /**
     * Move on to whatever this payment has just unlocked.
     *
     * Called once the money columns have been re-derived from the ledger, and
     * it is the one place the two subjects genuinely part company: money in on
     * an order can mean a goat changed hands at a door, while money in on a
     * room means only that the room is now held. Neither rule makes sense for
     * the other, so neither lives in the service.
     */
    public function settleAfterPayment(?Payment $trigger): static;

    /** @see \Illuminate\Database\Eloquent\Model::fresh() */
    public function fresh($with = []);

    /** @see \Illuminate\Database\Eloquent\Model::getKey() */
    public function getKey();
}
