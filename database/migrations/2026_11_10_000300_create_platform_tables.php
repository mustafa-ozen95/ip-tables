<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Platform tabloları (kardeş panellerle aynı şema): kişiye özel yetkiler, denetim
 * zinciri, hata kayıtları, ayarlar, merkez kimlik bağları ve API jetonları.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 40);
            // 0: bütün kayıtlar (UserPermission::ALL_RECORDS).
            $table->unsignedBigInteger('resource_id')->default(0);
            $table->string('action', 40);
            $table->string('effect', 10)->default('allow');
            $table->timestamps();

            $table->unique(['user_id', 'resource', 'resource_id', 'action'], 'user_permission_unique');
            $table->index(['user_id', 'resource', 'action']);
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 20)->default('audit');
            $table->unsignedTinyInteger('chain_version')->nullable();
            // Yabancı anahtar YOK: kullanıcı silinince denetim satırı değişmemeli
            // (zincirin özeti satırın kendisinden hesaplanır).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_name');
            $table->string('action', 30);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary');
            $table->string('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
            $table->index('user_id');
            $table->index(['chain', 'id']);
        });

        Schema::create('activity_chain_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('anchor_hash', 64)->nullable();
            $table->unsignedBigInteger('head_id')->nullable();
            $table->string('head_hash', 64)->nullable();
            $table->unsignedBigInteger('rows')->default(0);
            $table->string('mac', 64);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('error_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('signature', 64)->unique();
            $table->string('level', 20)->default('error');
            $table->string('type');
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('context_label')->nullable();
            $table->string('method', 10)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('trace')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['resolved_at', 'last_seen_at']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('kimlik');
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('merkez_cikis_ms')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->unique(['provider', 'user_id']);
        });

        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Jetonun kendisi SAKLANMAZ; yalnız özeti.
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->json('abilities')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('error_logs');
        Schema::dropIfExists('activity_chain_state');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('user_permissions');
    }
};
