<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('product', 30)->index();
            $table->string('question', 500);
            $table->text('answer');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->text('quote');
            $table->string('author_name');
            $table->string('author_title')->nullable();
            $table->foreignId('avatar_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body_html');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->timestamps();
        });

        // Leads and service requests: interest | application | inspection | contact | support.
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->index();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('product', 30)->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('alt_phone', 32)->nullable();
            $table->string('subject_line');
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('faqs');
    }
};
