<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('multipart_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bucket_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->uuid('upload_id')->unique();
            $table->string('content_type')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->unique(['bucket_id', 'key', 'upload_id']);
        });

        Schema::create('multipart_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('multipart_upload_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->string('etag', 64);
            $table->timestamps();
            $table->unique(['multipart_upload_id', 'part_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multipart_parts');
        Schema::dropIfExists('multipart_uploads');
    }
};
