<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goats', function (Blueprint $table) {
            // Null means the animal belongs to the house farm rather than a seller.
            $table->foreignId('seller_id')->nullable()->after('category_id')
                ->constrained()->cascadeOnDelete();

            // `status` is what the owner wants; `approval_status` is the admin's
            // verdict. A goat is only public when both agree.
            $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])
                ->default('approved')->after('status');
            $table->text('rejection_reason')->nullable()->after('approval_status');
            $table->timestamp('submitted_at')->nullable()->after('rejection_reason');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['approval_status', 'status']);
            $table->index('seller_id');
        });

        // Everything that already exists is house stock and stays live.
        DB::table('goats')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('goats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'rejection_reason', 'submitted_at', 'approved_at']);
        });
    }
};
