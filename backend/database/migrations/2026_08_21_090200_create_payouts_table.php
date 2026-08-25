<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('NPR');

            $table->enum('status', ['pending', 'processing', 'paid', 'failed'])->default('pending');
            $table->string('method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
