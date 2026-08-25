<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // How much the chosen payment method asks for up front to reserve the
            // animal. Null means the whole amount is due on delivery.
            $table->decimal('advance_required', 12, 2)->nullable()->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('advance_required');
        });
    }
};
