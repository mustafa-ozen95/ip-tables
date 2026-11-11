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
 * açılır (bkz. Ipv4SplitLayout, IpAddressWriter::split). Blok bir kerede
 * tam bölünmek zorunda değildir; bölünmemiş alan sonra bölünür.
 *
 * Bölme TEK KATTIR: parçalar eşit ya da farklı boyda olabilir ama iç içe
 * olmaz, /25'in içinde /26 durmaz. Parçanın boyu yerinde değişir: sıralı
 * iki eş parça BİRLEŞİR (/29 + /29 → /28), bir parça İKİYE AYRILIR
 * (/28 → /29 + /29); ikisinde de tek kat korunur.
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
                .'küçük parçalar bloğun ayrıntısındaki “Böl” ile açılır.',
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

    /**
     * Parçaların blok içinde kapladığı aralıklar (0–255).
     *
     * @param  Collection<int, IpAddress>  $parts
     * @return list<array{start: int, end: int}>
     */
    public function taken(IpAddress $block, Collection $parts): array
    {
        $base = ip2long(Cidr::make($block->address, self::BLOCK_PREFIX)->network);

        return $parts->map(function (IpAddress $part) use ($base): array {
            $start = ip2long(Cidr::make($part->address, $part->prefix)->network) - $base;

            return ['start' => $start, 'end' => $start + 2 ** (32 - $part->prefix) - 1];
        })->values()->all();
    }

    /** Bloğun bölünmemiş alanı var mı: parçalar 256 adresi doldurmuyor. */
    public function hasFreeSpace(IpAddress $block): bool
    {
        return array_sum(array_map(fn (array $t): int => $t['end'] - $t['start'] + 1, $this->taken($block, $this->parts($block))))
            < 2 ** (32 - self::BLOCK_PREFIX);
    }

    /**
     * Parçanın birleşebileceği eşi: aynı boyda, birlikte bir üst boyu
     * hizalı dolduran komşu parça (.0/29'un eşi .8/29). Yoksa null.
     */
    public function buddy(IpAddress $part): ?IpAddress
    {
        $block = $this->blockOf($part);

        if ($block === null || $part->prefix - 1 < Ipv4SplitLayout::MIN_PREFIX) {
            return null;
        }

        $own = ip2long(Cidr::make($part->address, $part->prefix)->network);
        $size = 2 ** (32 - $part->prefix);
        $other = long2ip($own % (2 * $size) === 0 ? $own + $size : $own - $size);

        return $this->parts($block)->first(
            fn (IpAddress $p): bool => $p->prefix === $part->prefix && Cidr::make($p->address, $p->prefix)->network === $other,
        );
    }

    /** Birleştirmenin hatası; birleşebiliyorsa null. */
    public function mergeError(IpAddress $part): ?string
    {
        if ($this->blockOf($part) === null) {
            return 'Yalnız bir bloğun parçası birleştirilir.';
        }

        if ($part->prefix - 1 < Ipv4SplitLayout::MIN_PREFIX) {
            return '/'.Ipv4SplitLayout::MIN_PREFIX.' parçalar birleşmez; bloğun tamamı için bölmeyi kaldırın.';
        }

        $buddy = $this->buddy($part);

        return match (true) {
            $buddy === null => 'Birleşecek eş parça yok: '.$this->buddyNotation($part).' bölünmüş ve aynı boyda olmalı.',
            filled($part->name) && filled($buddy->name) => 'İki parçanın da adı var; birleştirmek için birini boşaltın.',
            default => null,
        };
    }

    /**
     * Parçalamanın hatası; $prefix verilmezse yalnız parçanın kendisi
     * denetlenir (düğme çizilsin mi). Parçalanabiliyorsa null.
     */
    public function subdivideError(IpAddress $part, ?int $prefix = null): ?string
    {
        return match (true) {
            $this->blockOf($part) === null => 'Yalnız bir bloğun parçası parçalanır.',
            $part->prefix >= Ipv4SplitLayout::MAX_PREFIX => '/'.Ipv4SplitLayout::MAX_PREFIX.' tek adrestir, parçalanmaz.',
            $prefix !== null && ($prefix <= $part->prefix || $prefix > Ipv4SplitLayout::MAX_PREFIX) => '/'.$part->prefix
                .' parça /'.($part->prefix + 1).' ile /'.Ipv4SplitLayout::MAX_PREFIX." arasında bir boya bölünür; /{$prefix} olmaz.",
            default => null,
        };
    }

    /**
     * Parçanın bölünebileceği boylar: [30 => '/30 · 4 parça', …].
     *
     * @return array<int, string>
     */
    public function subdividePrefixes(IpAddress $part): array
    {
        $prefixes = range($part->prefix + 1, Ipv4SplitLayout::MAX_PREFIX);

        return array_combine($prefixes, array_map(
            fn (int $p): string => "/{$p} · ".(2 ** ($p - $part->prefix)).' parça',
            $prefixes,
        ));
    }

    /** Birleşince çıkacak parça: "192.0.2.0/28". */
    public function mergedNotation(IpAddress $part): string
    {
        return Cidr::make($part->address, $part->prefix - 1)->toString();
    }

    /** Parçanın eşinin olması gereken yer: "192.0.2.8/29". */
    public function buddyNotation(IpAddress $part): string
    {
        $own = ip2long(Cidr::make($part->address, $part->prefix)->network);
        $size = 2 ** (32 - $part->prefix);

        return long2ip($own % (2 * $size) === 0 ? $own + $size : $own - $size)."/{$part->prefix}";
    }

    /** Kaydı içeren, aynı gruptaki /24 blok; kayıt bir parça değilse null. */
    public function blockOf(IpAddress $record): ?IpAddress
    {
        if ($record->prefix <= self::BLOCK_PREFIX || ! $this->isIpv4($record->address)) {
            return null;
        }

        $own = Cidr::make($record->address, $record->prefix);

        /* Adresle süzülmez: hizasız girilmiş blok (192.0.2.5/24) da bloktur. */
        $block = $record->group->ipAddresses()
            ->where('prefix', self::BLOCK_PREFIX)
            ->get()
            ->first(fn (IpAddress $b): bool => Cidr::make($b->address, $b->prefix)->contains($own));

        /* Grup aynıdır; parts() onu yeniden sorgulamasın. */
        return $block?->setRelation('group', $record->group);
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
