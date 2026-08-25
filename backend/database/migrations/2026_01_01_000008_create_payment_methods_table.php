<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();  // cod, stripe, bkash ...
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_advance')->default(false);
            $table->decimal('advance_amount', 12, 2)->nullable();
            $table->json('config')->nullable(); // gateway keys, added later without code changes
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
