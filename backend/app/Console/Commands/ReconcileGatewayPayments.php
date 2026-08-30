<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\GatewayPaymentService;
use Illuminate\Console\Command;

/**
 * Catch the payments nobody told us about.
 *
 * The usual way an online payment is confirmed is the buyer's browser coming
 * back from the provider. That is also the least reliable moment in the whole
 * flow: they close the tab, the phone dies, the redirect is swallowed by a
 * banking app. The money still moved, and only the provider knows.
 *
 * So the redirect is treated as a convenience, and this as the safety net.
 */
class ReconcileGatewayPayments extends Command
{
    protected $signature = 'payments:reconcile
        {--minutes=5 : Leave attempts younger than this alone; they are probably still at the till}
        {--hours=48 : How far back to look}';

    protected $description = 'Ask eSewa and Khalti about payments that were started but never confirmed';

    public function handle(GatewayPaymentService $gateways): int
    {
        // A buyer standing at the payment page has a pending row too. Asking
        // about it would only ever say "pending", so give them a few minutes.
        $settledBefore = now()->subMinutes((int) $this->option('minutes'));
        $notOlderThan = now()->subHours((int) $this->option('hours'));

        $pending = Payment::query()
            ->where('type', 'payment')
            ->where('status', 'pending')
            ->whereIn('gateway', PaymentMethod::GATEWAY_CODES)
            ->where('created_at', '<', $settledBefore)
            ->where('created_at', '>', $notOlderThan)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing outstanding.');

            return self::SUCCESS;
        }

        $confirmed = 0;
        $closed = 0;

        foreach ($pending as $payment) {
            $result = $gateways->settle($payment->gateway, $payment->gateway_ref);

            match ($result?->status) {
                'confirmed' => $confirmed++,
                'rejected' => $closed++,
                default => null,
            };
        }

        $this->info(sprintf(
            'Checked %d: %d had been paid, %d closed, %d still in flight.',
            $pending->count(),
            $confirmed,
            $closed,
            $pending->count() - $confirmed - $closed,
        ));

        return self::SUCCESS;
    }
}
