<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('collection', 40)->index();
            $table->string('provider', 20)->default('cloudinary');
            $table->string('public_id')->unique();
            $table->string('folder')->nullable();
            $table->unsignedBigInteger('version')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('secure_url', 1024);
            $table->string('format', 20)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('dominant_color', 9)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'collection', 'position']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('documentable');
            $table->string('type', 40)->index();
            $table->string('name');
            $table->string('status', 30)->default('available');
            $table->string('disk', 30)->default('documents');
            $table->string('path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('hash', 64)->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_media_id']);
        });
        Schema::dropIfExists('media');
    }
};
