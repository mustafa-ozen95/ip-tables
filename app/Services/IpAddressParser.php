<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GroupKind;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Girilen metni adrese (ya da CIDR bloğuna) çevirir ve türünü belirler.
 *
 * Adres kanonik biçimde saklanır (inet_ntop): "2001:DB8:0::1" ile
 * "2001:db8::1" aynı kayıttır, ikinci kez eklenemez. Tür, adresin
 * config('ip.local_ranges') içinde olup olmamasından gelir.
 */
class IpAddressParser
{
    public function parse(string $input): ?ParsedAddress
    {
        [$address, $prefix] = array_pad(explode('/', trim($input), 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) inet_pton($address);
        $bits = strlen($packed) * 8;

        if (! $this->validPrefix($prefix, $bits)) {
            return null;
        }

        $canonical = (string) inet_ntop($packed);

        return new ParsedAddress(
            address: $canonical,
            prefix: $prefix === null ? $bits : (int) $prefix,
            kind: GroupKind::forAddress($bits === 128, $this->isLocal($canonical)),
            sortKey: $packed,
        );
    }

    public function isLocal(string $address): bool
    {
        return IpUtils::checkIp($address, (array) config('ip.local_ranges'));
    }

    private function validPrefix(?string $prefix, int $bits): bool
    {
        return $prefix === null
            || (ctype_digit($prefix) && strlen($prefix) <= 3 && (int) $prefix <= $bits);
    }
}
