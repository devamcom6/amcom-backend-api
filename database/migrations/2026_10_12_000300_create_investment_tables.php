<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('size_sqm')->nullable();
            $table->string('checkout_mode', 20)->default('direct');
            $table->unsignedTinyInteger('max_plots_per_order')->default(5);
            $table->boolean('is_published')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('estate_tenures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estate_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('months');
            $table->decimal('roi_percent', 6, 2);
            $table->timestamps();

            $table->unique(['estate_id', 'months']);
        });

        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('product', 30)->index();
            $table->string('reference', 40)->unique();
            $table->string('status', 30)->index();
            $table->foreignId('estate_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('principal');
            $table->unsignedSmallInteger('tenure_months');
            $table->decimal('roi_percent', 6, 2);
            $table->unsignedBigInteger('projected_return');
            $table->date('started_at')->nullable();
            $table->date('maturity_date')->nullable()->index();
            $table->timestamp('agreement_signed_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable()->index();
            $table->timestamp('payout_requested_at')->nullable();
            $table->timestamp('paid_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('reinvested_from_id')->nullable()->constrained('investments')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'product', 'status']);
            $table->index('created_at');
        });

        Schema::create('plots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estate_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->unsignedInteger('size_sqm');
            $table->unsignedBigInteger('price');
            $table->string('status', 20)->default('available');
            $table->foreignId('reserved_by_investment_id')->nullable()->constrained('investments')->nullOnDelete();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamps();

            $table->unique(['estate_id', 'code']);
            $table->index(['estate_id', 'status']);
        });

        Schema::create('investment_plot', function (Blueprint $table) {
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plot_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price');
            $table->primary(['investment_id', 'plot_id']);
        });

        Schema::create('agreement_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('accepted');
            $table->string('typed_signature_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('document_hash', 64);
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_signatures');
        Schema::dropIfExists('investment_plot');
        Schema::dropIfExists('plots');
        Schema::dropIfExists('investments');
        Schema::dropIfExists('estate_tenures');
        Schema::dropIfExists('estates');
    }
};
