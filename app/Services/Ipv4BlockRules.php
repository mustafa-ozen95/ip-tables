<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Support\Net\Cidr;
use Illuminate\Database\Eloquent\Collection;

/**
 * IPv4 blok kuralı. Elle eklenebilen IPv4 kaydı yalnız /24 BLOKTUR. Daha
 * küçüğü (/25–/32) elle eklenmez: blok BÖLÜNÜR ve parçalar kayıt olarak
 * açılır (bkz. Ipv4SplitLayout, IpAddressWriter::split).
 *
 * Bölme TEK KATTIR: parçalar eşit ya da farklı boyda olabilir ama bir
 * parça yeniden bölünmez, /25'in içinde /26 olmaz. Yanlış bölme parça parça
 * düzeltilmez; bölme kaldırılır, blok yeniden bölünür.
 *
 * Kural ekleme, düzenleme ve silmede AYNI yerden sınanır: parçanın adresini
 * değiştirmek ya da onu tek başına silmek bölmeyi bozardı, bölünmüş bloğu
 * taşımak parçaları bloksuz bırakırdı — o yollar da kapalıdır. Parçanın
 * adı, açıklaması ve alt grubu serbesttir; atama oradan yapılır.
 *
 * Kuraldan önce girilmiş /32'ler ve geniş bloklar silinmez; adları
 * değişebilir, yerleri yalnız /24'e çevrilerek değişir.
 *
 * IPv6 bu kurala girmez.
 */
class Ipv4BlockRules
{
    /** Elle eklenebilen tek IPv4 boyu; bölünen blok da budur. */
    public const BLOCK_PREFIX = 24;

    /**
     * Ekleme ($record yok) ve düzenlemenin blok kuralı hatası.
     */
    public function writeError(?IpAddress $record, AddressGroup $group, ParsedAddress $parsed): ?string
    {
        if (! $this->isIpv4($parsed->address)) {
            return null;
        }

        if ($record !== null && $this->unchanged($record, $group, $parsed)) {
            return null;
        }

        return match (true) {
            $record !== null && $this->blockOf($record) !== null => 'Parçanın adresi ve grubu değişmez; '
                .'gerekirse bloğun bölmesini kaldırıp yeniden bölün.',
            $record !== null && $this->isBlock($record) && $this->parts($record)->isNotEmpty() => 'Bu blok bölünmüş; '
                .'adresini ya da grubunu değiştirmek için önce bölmesini kaldırın.',
            $parsed->prefix !== self::BLOCK_PREFIX => 'IPv4’te yalnız /'.self::BLOCK_PREFIX.' blok eklenir; '
                .'küçük parçalar Blok planı’nda blok bölünerek açılır.',
            default => null,
        };
    }

    /** Silmenin blok kuralı hatası. */
    public function deleteError(IpAddress $record): ?string
    {
        if ($this->blockOf($record) !== null) {
            return 'Parça tek başına silinmez; boşaltmak için adını temizleyin ya da bloğun bölmesini kaldırın.';
        }

        $parts = $this->isBlock($record) ? $this->parts($record)->count() : 0;

        return $parts > 0 ? "Bu blok {$parts} parçaya bölünmüş; önce bölmesini kaldırın." : null;
    }

    /** Bölünebilen kayıt: IPv4 /24. */
    public function isBlock(IpAddress $record): bool
    {
        return $record->prefix === self::BLOCK_PREFIX && $this->isIpv4($record->address);
    }

    /**
     * Bloğun aynı gruptaki parçaları (içine düşen /25–/32 kayıtlar), adres
     * sırasıyla.
     *
     * @return Collection<int, IpAddress>
     */
    public function parts(IpAddress $block): Collection
    {
        $range = Cidr::make($block->address, $block->prefix);

        return $block->group->ipAddresses()
            ->where('prefix', '>', self::BLOCK_PREFIX)
            ->ordered()
            ->get()
            ->filter(fn (IpAddress $a): bool => $this->isIpv4($a->address) && $range->contains(Cidr::make($a->address, $a->prefix)))
            ->values();
    }

    /** Kaydı içeren, aynı gruptaki /24 blok; kayıt bir parça değilse null. */
    public function blockOf(IpAddress $record): ?IpAddress
    {
        if ($record->prefix <= self::BLOCK_PREFIX || ! $this->isIpv4($record->address)) {
            return null;
        }

        $own = Cidr::make($record->address, $record->prefix);

        /* Adresle süzülmez: hizasız girilmiş blok (192.0.2.5/24) da bloktur. */
        return $record->group->ipAddresses()
            ->where('prefix', self::BLOCK_PREFIX)
            ->get()
            ->first(fn (IpAddress $b): bool => Cidr::make($b->address, $b->prefix)->contains($own));
    }

    private function unchanged(IpAddress $record, AddressGroup $group, ParsedAddress $parsed): bool
    {
        return $group->is($record->group)
            && $parsed->address === $record->address
            && $parsed->prefix === $record->prefix;
    }

    private function isIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
