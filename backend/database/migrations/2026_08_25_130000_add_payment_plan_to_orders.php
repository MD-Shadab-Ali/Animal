<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the buyer agreed to pay, and when.
     *
     * Payment used to be asked for at dispatch, which meant the shop carried
     * the risk of holding an animal for someone who might never pay. The buyer
     * now chooses at checkout: all of it now, part of it now, or nothing until
     * the goat arrives. The order remembers which, because the amount it is
     * waiting for depends on it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_plan', ['full', 'advance', 'on_delivery'])
                ->default('on_delivery')
                ->after('payment_method');
        });

        // Everything placed before this existed was cash on delivery.
        DB::table('orders')->update(['payment_plan' => 'on_delivery']);

        DB::table('settings')->insertOrIgnore([
            [
                'group'      => 'marketplace',
                'key'        => 'advance_percent',
                'value'      => '30',
                'type'       => 'number',
                'label'      => 'Advance payment (%)',
                'hint'       => 'How much of the order a buyer pays up front when they choose to pay an advance. A payment method with its own fixed advance overrides this.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_plan');
        });

        DB::table('settings')->where('key', 'advance_percent')->delete();
    }
};
