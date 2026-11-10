<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GroupKind;
use App\Support\IpNotation;

/**
 * Çözümlenmiş bir adres ya da blok.
 *
 * `sortKey` adresin ikili hâlidir (inet_pton): metin sıralaması 10.0.0.10'u
 * 10.0.0.9'un önüne koyar, ikili sıralama koymaz.
 */
final readonly class ParsedAddress
{
    public function __construct(
        public string $address,
        public int $prefix,
        public GroupKind $kind,
        public string $sortKey,
    ) {}

    /** "192.0.2.0/24"; tek adreste önek yazılmaz: "192.0.2.10". */
    public function notation(): string
    {
        return IpNotation::format($this->address, $this->prefix);
    }
}
