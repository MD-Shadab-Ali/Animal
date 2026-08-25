<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goat_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goat_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['goat_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goat_images');
    }
};
