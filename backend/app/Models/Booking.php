<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Support\Homestay;
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
    /*
     * Named in the present tense, because a plan is a choice and not a receipt.
     *
     * This read "Paid in full now", which is a statement that the money has
     * arrived -- and it was printed on the booking from the moment it was
     * placed, directly beneath "Outstanding रु4,500". The guest was told the
     * stay was settled and unsettled in the same breath.
     */
    public const PAYMENT_PLANS = [
        'full' => 'Paying in full',
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

    /**
     * Newest first, by id rather than by timestamp.
     *
     * `latest()` sorts on created_at, which is only accurate to the second --
     * and a payment that confirms a booking and then checks it in writes two
     * rows inside the same second. The tie broke arbitrarily, so a guest could
     * be shown "Confirmed" sitting above "You are here": the same story told
     * backwards. The id is monotonic, so it is exactly the order things
     * happened in.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->latest('id');
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

    /**
     * The moment this guest may walk in: the arrival date at the farm's hour.
     *
     * The time is the farm's rather than the booking's, because a room is ready
     * when the farm says rooms are ready.
     *
     * Nothing gates check-in on this any more -- paying in full checks a guest
     * in whenever they pay. It is kept because it is what the arrival actually
     * means, and `scopeInHouse()` is the thing that now has to care about dates.
     */
    public function arrivalAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->check_in)
            ->setTimeFromTimeString(Homestay::checkInTime());
    }

    /** Still to come, and still holding a room. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', ['placed', 'confirmed'])
            ->where('check_out', '>=', now()->toDateString());
    }

    /**
     * Who is actually sleeping here tonight.
     *
     * The status alone stopped being able to answer this the moment paying in
     * full started checking people in: a stay settled in September for a
     * December week is `checked_in` from September. So the dates do the work,
     * and the status only says the stay was not called off.
     *
     * Half-open at the far end, like everything else here: somebody leaving
     * this morning is not in the house tonight.
     */
    public function scopeInHouse(Builder $query): Builder
    {
        return $query->where('status', 'checked_in')
            ->whereDate('check_in', '<=', today())
            ->whereDate('check_out', '>', today());
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
     * Move the stay on to whatever the money has just unlocked.
     *
     * Two steps, and they answer different questions. Any money at all, once
     * the advance is covered, means the room is held -- that is `confirmed`.
     * The *balance* is a stronger signal, because on an advance plan the rest
     * was only ever due on arrival, so paying it says the guest is here. That
     * is the same reasoning Order::closeIfSettled() uses for cash handed to a
     * rider at a door.
     */
    public function settleAfterPayment(?Payment $trigger): static
    {
        // A refund is money leaving. Confirming one must never read as a
        // booking becoming more paid for than it was.
        if ($trigger?->isRefund()) {
            return $this;
        }

        $booking = $this;

        if ($booking->status === 'placed'
            && (float) $booking->paid_amount > 0
            && ! $booking->awaiting_advance
        ) {
            $booking->update(['status' => 'confirmed']);

            $booking = $booking->fresh();
        }

        return $booking->checkInIfSettled();
    }

    /**
     * Settling up checks the guest in.
     *
     * Money in full, and the stay moves on -- the same rule the order applies
     * when a rider's cash closes it. There is deliberately no check on whether
     * the arrival has come round: the farm's decision is that a paid stay is a
     * confirmed arrival, and a guest who has paid everything should not have to
     * wait on a date for their booking to say so.
     *
     * The cost of that is worth writing down, because something else has to
     * carry it. A stay paid for in September and starting in December is
     * `checked_in` from September, so `status` alone can no longer answer "who
     * is sleeping here tonight". `scopeInHouse()` answers that with the dates,
     * and the admin's "in the house now" filter goes through it.
     */
    private function checkInIfSettled(): static
    {
        if (! Setting::get('auto_check_in_on_payment', true)) {
            return $this;
        }

        /*
         * Advance plans only, and this is the whole of the rule.
         *
         * What makes a final payment mean "the guest is here" is not that it
         * settles the bill -- it is *when it was due*. An advance plan says the
         * rest is payable on arrival, so the rest arriving is the arrival. A
         * full plan is paid at the moment of booking, weeks before anybody sets
         * off; treating that as a check-in marks a guest present the instant
         * they finish paying.
         *
         * Order::closeIfSettled() draws the same line for the same reason, and
         * excludes `full` in exactly these words: "anything else was paid
         * before the journey began".
         */
        if ($this->payment_plan !== 'advance') {
            return $this;
        }

        if ($this->status !== 'confirmed' || ! $this->isFullyPaid()) {
            return $this;
        }

        // Said on the history row, because "confirmed → checked in" with no
        // note reads as a member of staff having done it.
        $this->statusNote = 'Checked in — the balance was settled on arrival';

        $this->update(['status' => 'checked_in']);

        return $this->fresh();
    }
}
