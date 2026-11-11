<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listenin sırası (IpAddress::scopeOrdered: sort_key, prefix).
 *
 * Arama yazdıkça istek atar; her istekte kayıtlar bu sırayla gelir. Grup
 * seçilmemişken var olan (address_group_id, sub_group_id, sort_key) dizini
 * sıralamaya yaramıyordu.
 *
 * Arama sütunlarına (ad, açıklama) dizin YOK: `like '%…%'` baştaki joker
 * yüzünden B-tree dizinini kullanamaz; eklenseydi yalnızca yazmayı
 * yavaşlatırdı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->index(['sort_key', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropIndex(['sort_key', 'prefix']);
        });
    }
};
