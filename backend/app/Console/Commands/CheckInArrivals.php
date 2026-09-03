<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Catch any paid-up booking that never got checked in.
 *
 * Paying in full checks a guest in on the spot, so in the ordinary course this
 * finds nothing -- which is the point. It exists for the cases where the
 * ordinary course did not run: a stay settled while the farm had automatic
 * check-in switched off and then switched it back on, or a booking staff moved
 * back to `confirmed` by hand.
 *
 * The same reconciliation job `payments:reconcile` does for money, and it earns
 * its keep the same way: by being the thing that notices when the live path was
 * not there to do it.
 */
class CheckInArrivals extends Command
{
    protected $signature = 'bookings:check-in-arrivals';

    protected $description = 'Check in any booking that is paid in full but still confirmed';

    public function handle(): int
    {
        // The same switch the payment path respects. A farm that would rather
        // hand over keys by hand gets one answer, not two.
        if (! Setting::get('auto_check_in_on_payment', true)) {
            $this->info('Automatic check-in is switched off.');

            return self::SUCCESS;
        }

        $due = Booking::query()
            ->where('status', 'confirmed')
            // Advance plans only, matching the live path. A stay paid in full
            // at booking was settled before anybody set off, so its payment
            // says nothing about whether the guest has arrived.
            ->where('payment_plan', 'advance')
            // A stay whose checkout has already passed is a question for staff.
            // Marking it "here" days after they left would answer it wrongly,
            // and would put a finished stay back on the in-house list.
            ->whereDate('check_out', '>', today())
            ->get()
            ->filter(fn (Booking $booking) => $booking->isFullyPaid());

        foreach ($due as $booking) {
            // Said on the history row, so nobody reads this as a member of
            // staff having walked somebody to their room.
            $booking->statusNote = 'Checked in automatically — paid in full';

            $booking->update(['status' => 'checked_in']);

            $this->line("  {$booking->booking_number} — {$booking->room_name} — {$booking->guest_name}");
        }

        $this->info($due->count().' booking(s) checked in.');

        return self::SUCCESS;
    }
}
