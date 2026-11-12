<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Link to the existing account whose phone matches the referrer phone (referral rewards later).
        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('referrer_user_id')->nullable()->after('referrer_phone')->constrained('users')->nullOnDelete();
        });

        // Direct-purchase flows (buyback orders, capital investments) capture the referrer too.
        Schema::table('investments', function (Blueprint $table) {
            $table->string('referrer_name', 120)->nullable()->after('reinvested_from_id');
            $table->string('referrer_phone', 20)->nullable()->after('referrer_name');
            $table->foreignId('referrer_user_id')->nullable()->after('referrer_phone')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referrer_user_id');
            $table->dropColumn(['referrer_name', 'referrer_phone']);
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referrer_user_id');
        });
    }
};
