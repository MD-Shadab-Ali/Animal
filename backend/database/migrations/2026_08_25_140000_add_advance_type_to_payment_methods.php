<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a method's advance is a share of the order or a flat sum.
     *
     * A flat advance is wrong for a shop whose goats run from 15,000 to 72,000
     * — the same 5,000 is a third of one animal and a fourteenth of another —
     * so the amount can now be read as a percentage instead.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->enum('advance_type', ['percent', 'fixed'])
                ->default('percent')
                ->after('advance_amount');
        });

        // Whatever was already entered was a flat sum, so keep reading it that way.
        DB::table('payment_methods')
            ->whereNotNull('advance_amount')
            ->update(['advance_type' => 'fixed']);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('advance_type');
        });
    }
};
