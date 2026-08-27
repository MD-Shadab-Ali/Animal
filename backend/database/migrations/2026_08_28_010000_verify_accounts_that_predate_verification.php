<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Treat every account that existed before verification as verified.
 *
 * Sign-in now refuses an unverified account. Every row written before this
 * feature has `email_verified_at` null -- not because anyone failed to prove
 * their address, but because nobody was ever asked. Without this, adding the
 * check would lock out every existing customer, seller and admin at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update([
            'email_verified_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Not reversible: which accounts were verified before this ran is not
        // recorded anywhere, and guessing would sign people out for no reason.
    }
};
