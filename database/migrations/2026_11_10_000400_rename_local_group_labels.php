<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Yerleşik grupların adı "Yerel IPv4/IPv6" yerine "Local IPv4/IPv6".
 *
 * Yalnız adı hâlâ eski etiket olan grup değişir; kullanıcının yeniden
 * adlandırdığı gruba dokunulmaz.
 */
return new class extends Migration
{
    private const NAMES = [
        'local-ipv4' => ['Yerel IPv4', 'Local IPv4'],
        'local-ipv6' => ['Yerel IPv6', 'Local IPv6'],
    ];

    public function up(): void
    {
        foreach (self::NAMES as $kind => [$old, $new]) {
            DB::table('address_groups')->where('kind', $kind)->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $kind => [$old, $new]) {
            DB::table('address_groups')->where('kind', $kind)->where('name', $new)->update(['name' => $old]);
        }
    }
};
