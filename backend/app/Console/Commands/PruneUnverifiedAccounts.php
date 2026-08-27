<?php

namespace App\Console\Commands;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Clear out signups that never proved their address.
 *
 * An unverified row still holds the email against anyone else trying to use
 * it, so a typo or an abandoned signup would lock that address out for good.
 * Only accounts that never verified and own nothing are removed.
 */
class PruneUnverifiedAccounts extends Command
{
    protected $signature = 'accounts:prune-unverified {--hours=24}';

    protected $description = 'Delete accounts that never verified their email, and expired codes';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $stale = User::whereNull('email_verified_at')
            ->where('created_at', '<', $cutoff)
            ->where('role', 'customer')
            // Anything with a history behind it is a real account with a
            // verification problem, not an abandoned signup.
            ->whereDoesntHave('orders')
            // And only accounts that were actually asked to verify. Accounts
            // that predate this feature have no code against their name, and
            // deleting them for failing a test they were never given is how
            // this command wiped two real accounts the first time it ran.
            ->whereIn('email', EmailOtp::where('purpose', EmailOtp::PURPOSE_REGISTER)->pluck('email'))
            ->get();

        foreach ($stale as $user) {
            EmailOtp::where('email', $user->email)->delete();
            $user->forceDelete();
        }

        $codes = EmailOtp::where('expires_at', '<', now()->subDay())->delete();

        $this->info("Removed {$stale->count()} unverified accounts and {$codes} stale codes.");

        return self::SUCCESS;
    }
}
