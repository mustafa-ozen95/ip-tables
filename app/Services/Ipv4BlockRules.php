<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GroupKind;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Support\Net\Cidr;
use Illuminate\Support\Collection;

/**
 * IPv4 blok kuralı: /24'ten küçük (/25–/32) bir kayıt DOĞRUDAN eklenmez,
 * yalnız aynı gruptaki kayıtlı bir bloğun (/24 ya da daha geniş) İÇİNE
 * eklenir. Blok adres alanını tanımlar, içindekiler ondan yapılan
 * atamalardır (bkz. Ipv4BlockPlan).
 *
 * Kural ekleme, düzenleme ve silmede AYNI yerden sınanır: içinde atama
 * olan bir bloğu silmek ya da başka yere taşımak atamaları bloksuz
 * bırakırdı — o yol da kapalıdır.
 *
 * IPv6 bu kurala girmez.
 */
class Ipv4BlockRules
{
    /** Doğrudan eklenebilen en küçük blok. */
    public const BLOCK_PREFIX = 24;

    public function needsParent(string $address, int $prefix): bool
    {
        return $this->isIpv4($address) && $prefix > self::BLOCK_PREFIX;
    }

    /** Grupta bu adresi içeren bir blok var mı ($except dışında). */
    public function hasParent(AddressGroup $group, string $address, int $prefix, ?IpAddress $except = null): bool
    {
        $target = Cidr::make($address, $prefix);

        return $this->blocks($group, $except)
            ->contains(fn (IpAddress $block): bool => Cidr::make($block->address, $block->prefix)->contains($target));
    }

    /**
     * Bu blok silinirse ya da yeni yerine ($group, $address/$prefix)
     * taşınırsa bloksuz kalacak atamaların sayısı. Başka bir blok da onları
     * içeriyorsa ya da yeni yer hâlâ içeriyorsa bloksuz kalmazlar.
     */
    public function orphans(IpAddress $block, ?AddressGroup $group = null, ?string $address = null, ?int $prefix = null): int
    {
        if ($this->needsParent($block->address, $block->prefix) || ! $this->isIpv4($block->address)) {
            return 0;
        }

        $range = Cidr::make($block->address, $block->prefix);
        $moved = $group !== null && $group->is($block->group) && $address !== null && $this->isIpv4($address)
            ? Cidr::make($address, (int) $prefix)
            : null;

        return $block->group->ipAddresses()
            ->where('prefix', '>', self::BLOCK_PREFIX)
            ->whereKeyNot($block->getKey())
            ->get()
            ->filter(function (IpAddress $a) use ($block, $range, $moved): bool {
                if (! $this->isIpv4($a->address)) {
                    return false;
                }
                $own = Cidr::make($a->address, $a->prefix);

                return $range->contains($own)
                    && ! $moved?->contains($own)
                    && ! $this->hasParent($block->group, $a->address, $a->prefix, except: $block);
            })
            ->count();
    }

    public function orphanMessage(int $count): string
    {
        return "Bu blokta {$count} kayıt var; blok kalkarsa onlar bloksuz kalır. Önce onları silin ya da taşıyın.";
    }

    public function message(int $prefix): string
    {
        return "/{$prefix} doğrudan eklenemez; önce bu adresi içeren /".self::BLOCK_PREFIX
            .' (ya da daha geniş) bloğu aynı gruba ekleyin.';
    }

    /** @return Collection<int, IpAddress> */
    private function blocks(AddressGroup $group, ?IpAddress $except)
    {
        if ($group->kind === GroupKind::Ipv6 || $group->kind === GroupKind::LocalIpv6) {
            return collect();
        }

        return $group->ipAddresses()
            ->where('prefix', '<=', self::BLOCK_PREFIX)
            ->when($except, fn ($q, IpAddress $e) => $q->whereKeyNot($e->getKey()))
            ->get()
            ->filter(fn (IpAddress $b): bool => $this->isIpv4($b->address))
            ->values();
    }

    private function isIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
