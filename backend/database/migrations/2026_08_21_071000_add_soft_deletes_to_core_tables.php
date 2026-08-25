<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders and customer accounts are records a shop should never lose to a
     * mis-click, so they are archived rather than deleted.
     */
    public function up(): void
    {
        foreach (['orders', 'users', 'categories', 'reviews'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'users', 'categories', 'reviews'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
