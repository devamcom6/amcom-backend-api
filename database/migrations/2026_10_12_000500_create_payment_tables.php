<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('provider', 20)->default('paystack');
            $table->string('provider_reference')->nullable()->index();
            $table->string('access_code')->nullable();
            $table->string('authorization_url', 512)->nullable();
            $table->string('payable_type', 30);
            $table->unsignedBigInteger('payable_id');
            $table->string('description');
            $table->unsignedBigInteger('amount'); // whole Naira
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('method', 20);
            $table->string('channel', 30)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('gateway_response')->nullable();
            // Non-sensitive card/bank descriptors only (brand, last4, bank) — never authorization codes.
            $table->json('authorization_data')->nullable();
            $table->json('metadata')->nullable();
            $table->string('callback_url', 512)->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('effects_applied_at')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event', 60);
            $table->string('idempotency_key', 128)->unique();
            $table->string('reference')->nullable()->index();
            $table->json('payload');
            $table->string('status', 20)->default('received');
            $table->string('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('transactions');
    }
};
