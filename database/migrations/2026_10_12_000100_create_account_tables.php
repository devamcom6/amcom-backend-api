<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('state_of_origin', 100)->nullable();
            $table->string('lga', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('alt_phone', 32)->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_relationship', 100)->nullable();
            $table->string('next_of_kin_phone', 32)->nullable();
            $table->string('next_of_kin_email')->nullable();
            $table->string('onboarding_step', 20)->default('identity');
            $table->timestamps();
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference')->nullable()->index();
            $table->string('id_type', 40);
            // Only the last four digits are retained (data-minimisation).
            $table->string('id_number_last4', 4)->nullable();
            $table->string('status', 20)->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable()->index();
            $table->string('purpose', 30);
            $table->string('code_hash');
            $table->json('meta')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'user_id']);
            $table->index(['purpose', 'email']);
        });

        Schema::create('login_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('device')->nullable();
            $table->string('location')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('signed_out_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'fingerprint']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bank_code', 20);
            $table->string('bank_name');
            $table->text('account_number'); // encrypted at rest
            $table->string('account_last4', 4);
            $table->string('account_name');
            $table->string('recipient_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('login_activities');
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('user_profiles');
    }
};
