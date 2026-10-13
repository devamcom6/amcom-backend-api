<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A single table backs both owner listings and the public marketplace:
        // a listing becomes a marketplace property once it is `published`.
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('listing_type', 20)->index();
            $table->string('property_type', 30)->index();
            $table->string('state', 100);
            $table->string('city', 100)->index();
            $table->string('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('size_sqm')->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable()->index();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedBigInteger('price')->index();
            $table->string('condition', 30)->nullable();
            $table->string('furnishing', 30)->nullable();
            $table->json('amenities')->nullable();
            $table->json('features')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->string('rejection_reason')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('accuracy_confirmed_at')->nullable();
            $table->timestamp('fee_paid_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('property_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('holding_type', 30)->index();
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('purchase_price')->nullable();
            $table->date('acquired_on')->nullable();
            $table->string('title_document')->nullable();
            $table->string('ownership_status')->nullable();
            $table->unsignedBigInteger('rate')->nullable();
            $table->string('rate_period', 10)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('renewal_date')->nullable();
            $table->date('next_payment_date')->nullable();
            $table->string('term_label')->nullable();
            $table->timestamps();
        });

        Schema::create('holding_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_holding_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('transaction_id')->nullable()->unique();
            $table->date('date');
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_holding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
        Schema::dropIfExists('holding_payments');
        Schema::dropIfExists('property_holdings');
        Schema::dropIfExists('properties');
    }
};
