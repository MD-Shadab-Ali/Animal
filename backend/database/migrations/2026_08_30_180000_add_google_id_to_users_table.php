<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Google's subject id, which is stable for an account even when the
            // person changes the address on it. Matched on before the email for
            // that reason.
            $table->string('google_id')->nullable()->unique()->after('email');
        });

        Schema::table('users', function (Blueprint $table) {
            // An account created through Google has no password to store, and a
            // random one would only be a password nobody can use. Everything
            // that checks a password now has to cope with null -- see the guards
            // in AuthController::login() and ::changePassword().
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });

        // password is deliberately left nullable. Any account created through
        // Google has none, and putting the NOT NULL back would fail on exactly
        // the rows this migration made possible.
    }
};
