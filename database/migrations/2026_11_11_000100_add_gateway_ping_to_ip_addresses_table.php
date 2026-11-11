<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway ping sonucu (bkz. GatewayPing). Yalnız zamanlayıcı yazar; formdan
 * gelmez. Boş: kayıt denetlenmiyor (bölünmüş bloğun kendisi, IPv6, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->boolean('gateway_up')->nullable();
            $table->timestamp('gateway_checked_at')->nullable();
            $table->timestamp('gateway_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropColumn(['gateway_up', 'gateway_checked_at', 'gateway_seen_at']);
        });
    }
};
