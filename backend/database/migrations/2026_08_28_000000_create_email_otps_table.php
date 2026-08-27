<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived codes emailed to prove an address is real and reachable.
 *
 * Keyed on the address rather than a user, because at registration there is no
 * user yet -- proving the address is the thing that decides whether one should
 * exist. `purpose` keeps signup and password-reset codes apart so a code
 * issued for one can never be spent on the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('purpose', 32);

            // Hashed, never stored in the clear: a leaked table would
            // otherwise hand over every live code.
            $table->string('code_hash');

            // Guessing costs attempts, so a six-digit code cannot be walked.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Backs the resend cooldown.
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            // One live code per address per purpose; asking again replaces it.
            $table->unique(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otps');
    }
};
