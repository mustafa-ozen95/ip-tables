<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Adresin ekrandaki yazımı. Tek adres (/32, /128) öneksiz yazılır; blok
 * önekiyle.
 */
final class IpNotation
{
    public static function format(string $address, int $prefix): string
    {
        $bits = str_contains($address, ':') ? 128 : 32;

        return $prefix === $bits ? $address : "{$address}/{$prefix}";
    }
}
