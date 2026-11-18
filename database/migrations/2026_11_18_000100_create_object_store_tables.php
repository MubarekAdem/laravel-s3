<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void {
 Schema::create('buckets', function(Blueprint $t){$t->id();$t->string('name')->unique();$t->unsignedBigInteger('quota_bytes')->nullable();$t->timestamps();});
 Schema::create('objects', function(Blueprint $t){$t->id();$t->foreignId('bucket_id')->constrained()->cascadeOnDelete();$t->string('key');$t->string('path')->unique();$t->unsignedBigInteger('size');$t->string('etag',64);$t->string('content_type')->nullable();$t->json('metadata')->nullable();$t->boolean('is_public')->default(false);$t->timestamp('deleted_at')->nullable();$t->timestamps();$t->unique(['bucket_id','key']);});
 Schema::create('access_keys', function(Blueprint $t){$t->id();$t->string('access_key')->unique();$t->text('secret_encrypted');$t->json('scopes')->nullable();$t->timestamp('last_used_at')->nullable();$t->timestamp('revoked_at')->nullable();$t->timestamps();});
 Schema::create('audit_events', function(Blueprint $t){$t->id();$t->string('request_id')->nullable();$t->string('actor')->nullable();$t->string('action');$t->string('bucket')->nullable();$t->text('object_key')->nullable();$t->string('ip',45)->nullable();$t->json('context')->nullable();$t->timestamps();});
 } public function down(): void {Schema::dropIfExists('audit_events');Schema::dropIfExists('access_keys');Schema::dropIfExists('objects');Schema::dropIfExists('buckets');} };
