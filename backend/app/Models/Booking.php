<?php

namespace App\Models;

use App\Contracts\Payable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room held for somebody, for a run of nights.
 *
 * Everything about the money here is the order's arrangement, deliberately:
 * the same plans, the same paid_amount and payment_status derived from the same
 * ledger by the same service, the same advance. A guest who has bought a goat
 * and then books a room should not have to learn a second way of paying, and
 * staff should not have to hold two meanings of "partially paid" in their head.
 *
 * Everything about the *lifecycle* is its own, because a stay is not a
 * delivery. An order ends when an animal reaches somebody; a booking ends when
 * a guest leaves a room, and no amount of money in the ledger can witness
 * either.
 */
class Booking extends Model implements Payable
{
    use SoftDeletes;

    protected $fillable = [
        'booking_number', 'room_id', 'user_id',
        'guest_name', 'guest_phone', 'guest_email', 'guest_notes',
        'check_in', 'check_out', 'nights', 'guests',
        'room_name', 'room_thumbnail', 'rate_per_night',
        'room_charge', 'extra_guest_charge', 'discount', 'total', 'currency',
        'payment_method', 'payment_plan', 'payment_status', 'paid_amount',
        'advance_required', 'transaction_id',
        'status', 'admin_note', 'checked_in_at', 'checked_out_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'rate_per_night' => 'decimal:2',
            'room_charge' => 'decimal:2',
            'extra_guest_charge' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'advance_required' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public const STATUSES = [
        'placed' => 'Placed',
        'confirmed' => 'Confirmed',
        'checked_in' => 'Checked in',
        'checked_out' => 'Checked out',
        'cancelled' => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        'placed' => 'warning',
        'confirmed' => 'info',
        'checked_in' => 'primary',
        'checked_out' => 'success',
        'cancelled' => 'danger',
    ];

    /** Forward-only progression through the stay. */
    public const FLOW = ['placed', 'confirmed', 'checked_in', 'checked_out'];

    /**
     * Two plans, where the order has three.
     *
     * There is no pay-on-arrival, and its absence is the point: a room held for
     * a guest who never comes and never paid is a night the farm could not sell
     * to anybody else. The order can afford that third plan because a rider
     * collects cash at a door, for an animal somebody is standing in front of.
     */
    public const PAYMENT_PLANS = [
        'full' => 'Paid in full now',
        'advance' => 'Advance now, the rest on arrival',
    ];

    /**
     * A note to attach to the next status change.
     *
     * Transient, not a column, exactly as on the order: the history row is
     * where it belongs, and setting this before a save lets the observer record
     * why a stay moved rather than only that it did.
     */
    public ?string $statusNote = null;

    /**
     * The night count follows the dates, always.
     *
     * `nights` is stored rather than derived so a booking cannot re-price
     * itself out from under an agreed total -- but stored is not the same as
     * stale. Staff moving a stay in the admin panel would otherwise leave a
     * three-night booking claiming two, and that number is what the guest sees,
     * what the rate is quoted against, and what a member of staff counts beds
     * from.
     *
     * The money is deliberately *not* recalculated here. That is a decision
     * about what somebody agreed to pay, and it belongs to the person moving
     * the dates -- silently re-pricing would also wipe out a discount given
     * over the phone.
     */
    protected static function booted(): void
    {
        static::saving(function (self $booking) {
            if (! $booking->exists || $booking->isDirty(['check_in', 'check_out'])) {
                $booking->nights = count($booking->occupiedNights());
            }
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The nights this booking is holding. */
    public function nights(): HasMany
    {
        return $this->hasMany(BookingNight::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->latest();
    }

    /*
    |--------------------------------------------------------------------------
    | The nights a stay occupies
    |--------------------------------------------------------------------------
    |
    | Half-open, and this is the only place that rule is written down: the guest
    | sleeps on the check-in date and leaves on the check-out date, so a stay
    | from the 4th to the 6th holds the 4th and the 5th. The 6th belongs to
    | whoever arrives that afternoon.
    |
    | Getting this wrong by a day is the classic booking bug, and it fails in
    | two opposite ways that both look like a broken calendar: include the
    | checkout date and back-to-back stays are refused, drop the check-in date
    | and two guests are sold the same first night.
    |
    */

    /** @return list<string> dates as Y-m-d */
    public static function nightsBetween(CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        $nights = [];

        for (
            $night = $checkIn->startOfDay();
            $night->lt($checkOut->startOfDay());
            $night = $night->addDay()
        ) {
            $nights[] = $night->toDateString();
        }

        return $nights;
    }

    /** @return list<string> dates as Y-m-d */
    public function occupiedNights(): array
    {
        return self::nightsBetween(
            CarbonImmutable::parse($this->check_in),
            CarbonImmutable::parse($this->check_out),
        );
    }

    /**
     * This booking is keeping the room off the market.
     *
     * A finished stay still holds its nights. They are in the past and nobody
     * can book them anyway, and releasing them would quietly erase the record
     * of the room having been occupied at all. Only a cancellation gives nights
     * back, because only a cancellation means the stay is not happening.
     */
    public function holdsRoom(): bool
    {
        return $this->status !== 'cancelled';
    }

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | The order's arithmetic, unchanged. paid_amount and payment_status are
    | never written here by hand -- PaymentService derives both from the
    | confirmed rows in the ledger, so the two can never drift apart.
    |
    */

    /** What the guest still owes altogether. */
    public function getBalanceDueAttribute(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    /**
     * The money is all in.
     *
     * A cent of tolerance, because the total and the sum of the payments are
     * two separate decimal roundings and must not disagree over 0.001.
     */
    public function isFullyPaid(): bool
    {
        return (float) $this->paid_amount + 0.01 >= (float) $this->total;
    }

    /** An advance was asked for and has not arrived yet. */
    public function getAwaitingAdvanceAttribute(): bool
    {
        return $this->advance_required !== null
            && (float) $this->paid_amount < (float) $this->advance_required;
    }

    /**
     * What the guest owes *right now*.
     *
     * On an advance plan that is the advance until it is covered, and the rest
     * once they are here. On a full plan it is simply the balance.
     */
    public function getAmountDueNowAttribute(): float
    {
        if ($this->payment_plan === 'advance' && $this->awaiting_advance) {
            return round((float) $this->advance_required - (float) $this->paid_amount, 2);
        }

        return $this->balance_due;
    }

    /**
     * Money taken that the booking no longer asks for.
     *
     * A stay shortened by staff after the guest arrived re-prices downwards,
     * and somebody who paid in full is suddenly in credit. Nothing about that
     * is a cancellation -- the stay is going ahead -- so it needs its own
     * answer, or the money simply sits there.
     */
    public function getOverpaidAmountAttribute(): float
    {
        if ($this->status === 'cancelled') {
            return 0.0;
        }

        return max(0.0, round((float) $this->paid_amount - (float) $this->total, 2));
    }

    public function getRefundableAmountAttribute(): float
    {
        // A cancelled booking owes back everything it ever took.
        if ($this->status === 'cancelled') {
            return round((float) $this->paid_amount, 2);
        }

        return $this->overpaid_amount;
    }

    public function isRefundable(): bool
    {
        return $this->refundable_amount > 0;
    }

    public function getPaymentPlanLabelAttribute(): string
    {
        return self::PAYMENT_PLANS[$this->payment_plan] ?? $this->payment_plan;
    }

    /*
    |--------------------------------------------------------------------------
    | Where the stay has got to
    |--------------------------------------------------------------------------
    */

    /** The one status this booking may move to next, or null if it is finished. */
    public function nextStatus(): ?string
    {
        if (in_array($this->status, ['cancelled', 'checked_out'], true)) {
            return null;
        }

        $at = array_search($this->status, self::FLOW, true);

        if ($at === false) {
            return null;
        }

        return self::FLOW[$at + 1] ?? null;
    }

    public function canAdvanceTo(string $status): bool
    {
        if (in_array($this->status, ['cancelled', 'checked_out'], true)) {
            return false;
        }

        $from = array_search($this->status, self::FLOW, true);
        $to = array_search($status, self::FLOW, true);

        return $from !== false && $to !== false && $to > $from;
    }

    /**
     * The guest may still call it off.
     *
     * Only before they arrive. Once they have checked in the room was used, and
     * whatever happens next is a conversation about a refund rather than a
     * cancellation -- and cancelling would hand the nights back to the calendar
     * while somebody was asleep in them.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, ['placed', 'confirmed'], true);
    }

    /**
     * Nobody is handed a key on the strength of a booking alone.
     *
     * Confirmed means the money the plan asked for up front actually arrived,
     * which is the same gate the order applies: the state just before the
     * handover is the one that has to be paid for.
     */
    public function canCheckIn(): bool
    {
        return $this->status === 'confirmed';
    }

    /** A stay does not close with money outstanding, exactly as an order does not. */
    public function canCheckOut(): bool
    {
        return $this->isFullyPaid();
    }

    /** Still to come, and still holding a room. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', ['placed', 'confirmed'])
            ->where('check_out', '>=', now()->toDateString());
    }

    public function scopeInHouse(Builder $query): Builder
    {
        return $query->where('status', 'checked_in');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /*
    |--------------------------------------------------------------------------
    | Payable
    |--------------------------------------------------------------------------
    |
    | What PaymentService needs in order to take money for this without knowing
    | what it is. See App\Contracts\Payable.
    |
    */

    public function paymentForeignKey(): string
    {
        return 'booking_id';
    }

    public function payer(): ?User
    {
        return $this->user;
    }

    public function paymentTotal(): float
    {
        return (float) $this->total;
    }

    public function balanceDue(): float
    {
        return $this->balance_due;
    }

    public function amountDueNow(): float
    {
        return $this->amount_due_now;
    }

    public function paymentCurrency(): string
    {
        return (string) $this->currency;
    }

    public function defaultPaymentMethod(): ?string
    {
        return $this->payment_method;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function refundableAmount(): float
    {
        return $this->refundable_amount;
    }

    public function paymentReference(): string
    {
        return (string) $this->booking_number;
    }

    public function cancelledMessage(): string
    {
        return 'This booking has been cancelled.';
    }

    public function settledMessage(): string
    {
        return 'This booking is already paid in full.';
    }

    public function paymentSubjectNoun(): string
    {
        return 'booking';
    }

    public function paymentSubjectPath(): string
    {
        return '/account/bookings/'.$this->booking_number;
    }

    public function payerName(): string
    {
        return (string) $this->guest_name;
    }

    public function payerEmail(): ?string
    {
        return $this->guest_email;
    }

    public function payerPhone(): ?string
    {
        return $this->guest_phone;
    }

    /**
     * The room and the dates, on one line.
     *
     * What staff need in order to place a payment at a glance: which room is
     * being held, and when. A booking number alone sends them looking it up.
     *
     * @return list<string>
     */
    public function paymentSummaryLines(): array
    {
        return [sprintf(
            '%s — %d night%s, %s to %s',
            $this->room_name,
            (int) $this->nights,
            (int) $this->nights === 1 ? '' : 's',
            CarbonImmutable::parse($this->check_in)->format('j M'),
            CarbonImmutable::parse($this->check_out)->format('j M Y'),
        )];
    }

    /**
     * Money in means the room is held.
     *
     * That is the whole of it, and the restraint is deliberate. The order can
     * close itself on a payment because cash at the door is evidence a goat
     * arrived; nothing a guest pays is evidence that they turned up, walked
     * into a room, or left it. Those three are for a person to say, so this
     * only ever moves a booking off `placed`.
     */
    public function settleAfterPayment(?Payment $trigger): static
    {
        // A refund is money leaving. Confirming one must never read as a
        // booking becoming more paid for than it was.
        if ($trigger?->isRefund()) {
            return $this;
        }

        if ($this->status === 'placed'
            && (float) $this->paid_amount > 0
            && ! $this->awaiting_advance
        ) {
            $this->update(['status' => 'confirmed']);

            return $this->fresh();
        }

        return $this;
    }
}
