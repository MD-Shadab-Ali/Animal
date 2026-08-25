<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Public identity
            $table->string('farm_name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->string('logo')->nullable();
            $table->string('banner')->nullable();

            // Contact — kept separate from the user account so a farm can list a
            // different number from the one the owner signs in with.
            $table->string('contact_phone', 30);
            $table->string('contact_email')->nullable();
            $table->string('address_line')->nullable();
            $table->string('area')->nullable();
            $table->string('city');
            $table->string('postal_code', 20)->nullable();

            // Vetting
            $table->enum('status', ['pending', 'approved', 'suspended', 'rejected'])->default('pending');
            $table->string('national_id')->nullable();
            $table->string('id_document')->nullable();
            $table->string('trade_licence')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Money. Null commission means "use the platform default setting".
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->string('payout_method')->nullable();
            $table->string('payout_account_name')->nullable();
            $table->string('payout_account_number')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
