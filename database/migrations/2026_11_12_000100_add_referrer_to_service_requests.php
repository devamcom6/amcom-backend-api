<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('referrer_name', 120)->nullable()->after('alt_phone');
            $table->string('referrer_phone', 20)->nullable()->after('referrer_name');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['referrer_name', 'referrer_phone']);
        });
    }
};
