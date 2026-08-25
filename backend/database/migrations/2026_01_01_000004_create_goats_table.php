<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->unique();
            $table->string('thumbnail')->nullable();

            // Livestock attributes
            $table->string('breed')->nullable();
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->enum('gender', ['male', 'female'])->default('male');
            $table->string('color')->nullable();
            $table->unsignedTinyInteger('teeth')->nullable();
            $table->string('health_status')->nullable();
            $table->boolean('is_vaccinated')->default(false);
            $table->json('specs')->nullable(); // admin-defined label/value rows

            // Commerce
            $table->decimal('price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->unsignedInteger('stock')->default(1);
            $table->boolean('track_stock')->default(true);

            // Content
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('video_url')->nullable();

            // Flags
            $table->enum('status', ['draft', 'published', 'sold', 'archived'])->default('published');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_featured']);
            $table->index(['category_id', 'status']);
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goats');
    }
};
