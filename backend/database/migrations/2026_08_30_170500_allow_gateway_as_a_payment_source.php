<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A third kind of author for a payment row.
 *
 * Until now money was only ever recorded by a customer saying they sent it or
 * staff saying they saw it. A gateway is neither, and the distinction matters
 * when reading the ledger back: "gateway" means nobody vouched for this, a
 * provider was asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY source ENUM('customer','staff','gateway') NOT NULL DEFAULT 'customer'");
    }

    public function down(): void
    {
        // Anything recorded by a gateway reads best as staff-entered once the
        // distinction is gone; leaving it would break the enum.
        DB::table('payments')->where('source', 'gateway')->update(['source' => 'staff']);

        DB::statement("ALTER TABLE payments MODIFY source ENUM('customer','staff') NOT NULL DEFAULT 'customer'");
    }
};
