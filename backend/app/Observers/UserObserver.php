<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * End every live session when an account is disabled or changes role.
     *
     * The `active` middleware already refuses a disabled account's token, but
     * only when it is next used. Deleting the tokens here means the change
     * takes effect the instant it is saved, from whichever surface saved it --
     * the Filament form, a bulk action, a seeder or a console command.
     *
     * Role changes are included because the storefront caches the role it was
     * handed at sign-in to decide what to offer. Re-authenticating is what
     * refreshes it, and a demoted account should not keep being offered the
     * admin panel until it happens to sign out.
     */
    public function updated(User $user): void
    {
        $disabled = $user->wasChanged('is_active') && ! $user->is_active;

        if ($disabled || $user->wasChanged('role')) {
            $user->tokens()->delete();
        }
    }
}
