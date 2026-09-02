<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Services\BookingService;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the calendar honest, whoever writes to a booking.
 *
 * BookingService is the front door and wraps its writes in a transaction, but
 * it is not the only way a booking changes: staff shift dates in the admin
 * panel, a payment moves a stay from placed to confirmed, a guest cancels. If
 * the nights were written only by the service, any of those would leave
 * `booking_nights` describing a stay that no longer exists -- a room held for
 * dates nobody booked, or free on dates somebody did.
 *
 * So the nights follow the booking here, at the model, and the service's own
 * call is simply the first one through.
 */
class BookingObserver
{
    public function __construct(private BookingService $bookings) {}

    public function created(Booking $booking): void
    {
        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'user_id' => auth()->id(),
            'from_status' => null,
            'to_status' => $booking->status,
            'note' => 'Booking placed',
        ]);

        // Inside the caller's transaction. A clash here takes the booking row
        // down with it, which is the only acceptable outcome: a booking that
        // exists while holding no nights is a room sold to nobody.
        $this->bookings->hold($booking);
    }

    public function updating(Booking $booking): void
    {
        if (! $booking->isDirty('status')) {
            return;
        }

        $to = $booking->status;

        if ($to === 'checked_in' && ! $booking->checked_in_at) {
            $booking->checked_in_at = now();
        }

        if ($to === 'checked_out' && ! $booking->checked_out_at) {
            $booking->checked_out_at = now();
        }

        if ($to === 'cancelled' && ! $booking->cancelled_at) {
            $booking->cancelled_at = now();
        }

        /*
         * The last gate before a stay closes, and deliberately at the model
         * rather than on one screen: checked out means paid for, whoever is
         * asking. A guest on an advance plan settles the rest on arrival, and
         * closing the stay without it would write the balance off in silence.
         */
        if ($to === 'checked_out' && ! $booking->canCheckOut()) {
            throw ValidationException::withMessages([
                'status' => ['This booking cannot be checked out until it is paid for. '
                    .'Record the balance first.'],
            ]);
        }
    }

    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('status')) {
            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'user_id' => auth()->id(),
                'from_status' => (string) $booking->getOriginal('status'),
                'to_status' => $booking->status,
                // Whoever moved it may have said why. For a cancellation that
                // distinction turns out to be the whole story later.
                'note' => $booking->statusNote,
            ]);

            $booking->statusNote = null;
        }

        if ($this->holdChanged($booking)) {
            $this->bookings->hold($booking);
        }
    }

    /**
     * Something happened that changes which nights this booking is holding.
     *
     * Narrower than "anything changed", on purpose. Confirming a booking when
     * an advance lands is a status change that alters nothing about the
     * calendar, and rewriting every night of a stay each time money arrives is
     * work done to reach the state already there.
     *
     * A status change only matters when it crosses the cancelled line, which is
     * the one boundary where a stay starts or stops holding a room at all.
     */
    private function holdChanged(Booking $booking): bool
    {
        if ($booking->wasChanged(['check_in', 'check_out', 'room_id'])) {
            return true;
        }

        return $booking->wasChanged('status')
            && ($booking->status === 'cancelled' || $booking->getOriginal('status') === 'cancelled');
    }
}
