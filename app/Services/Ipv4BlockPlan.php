<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BadgeVariant;
use App\Models\IpAddress;
use App\Support\Net\Cidr;
use Illuminate\Support\Collection;

/**
 * Adres listesinin blok düzeni: kayıtlı /24 bloklar adres sırasıyla, her
 * birinin altında bölmeden çıkan parçalar (bkz. Ipv4BlockRules). Parçalar
 * tek kattır; liste bu yüzden iki düzeyden derine inmez.
 *
 * Doluluk ADI verilmiş parçalardır: bölme parçaları adsız açar, atama
 * parçaya ad vererek yapılır. Bölünmemiş blok "Boş" sayılır.
 *
 * Blok kısmen bölünebilir: parçaların arasında kalan BÖLÜNMEMİŞ ALAN da
 * parça tablosunda satır olarak durur, sonra bölünecek yer görünsün.
 *
 * Bloğa girmeyen her kayıt (IPv6, kuraldan önce girilmiş /32'ler, geniş
 * bloklar) "diğerleri"dir ve listede düz satır olarak durur.
 */
class Ipv4BlockPlan
{
    public function __construct(
        private Ipv4SplitLayout $layout = new Ipv4SplitLayout,
        private GatewayPingStatus $ping = new GatewayPingStatus,
    ) {}

    /**
     * Blok açık gelir: uuid'i $openBlock ise (bölmeden dönüş) ya da
     * seçili kayıt ($selected) onun parçasıysa.
     *
     * @param  Collection<int, IpAddress>  $records
     * @return array{blocks: list<array<string, mixed>>, others: list<IpAddress>}
     */
    public function build(Collection $records, ?string $openBlock = null, ?string $selected = null): array
    {
        $blocks = [];
        $rest = [];

        foreach ($records as $record) {
            $start = $this->ipv4($record->address);

            match (true) {
                $start === null => $rest[] = ['start' => null, 'record' => $record],
                $record->prefix === Ipv4BlockRules::BLOCK_PREFIX => $blocks[] = ['start' => $start & $this->mask(24), 'record' => $record],
                default => $rest[] = ['start' => $start & $this->mask($record->prefix), 'record' => $record],
            };
        }

        [$parts, $others] = $this->attach($blocks, $rest);

        /* Kayıtlar sort_key sırasıyla gelir; aynı /24 iki grupta varsa ikisi de çizilir. */
        $rows = array_map(
            fn (array $b): array => $this->block($b, $parts[$this->key($b['record'], $b['start'])] ?? [], $openBlock, $selected),
            $blocks,
        );

        return ['blocks' => $rows, 'others' => $others];
    }

    /**
     * Parçaları bloklarına dağıtır; aynı gruptaki bir /24'e düşmeyen kayıt
     * "diğerleri"ne gider.
     *
     * @param  list<array{start: int, record: IpAddress}>  $blocks
     * @param  list<array{start: int|null, record: IpAddress}>  $rest  IPv6'da start null.
     * @return array{0: array<string, list<array{start: int, record: IpAddress}>>, 1: list<IpAddress>}
     */
    private function attach(array $blocks, array $rest): array
    {
        $keys = array_flip(array_map(fn (array $b): string => $this->key($b['record'], $b['start']), $blocks));
        $parts = [];
        $others = [];

        foreach ($rest as $entry) {
            $key = $entry['start'] === null ? null : $this->key($entry['record'], $entry['start'] & $this->mask(24));

            if ($key !== null && $entry['record']->prefix > Ipv4BlockRules::BLOCK_PREFIX && isset($keys[$key])) {
                $parts[$key][] = $entry;
            } else {
                $others[] = $entry['record'];
            }
        }

        return [$parts, $others];
    }

    /**
     * @param  array{start: int, record: IpAddress}  $block
     * @param  list<array{start: int, record: IpAddress}>  $parts
     * @return array<string, mixed>
     */
    private function block(array $block, array $parts, ?string $openBlock, ?string $selected): array
    {
        usort($parts, fn (array $a, array $b): int => [$a['start'], $a['record']->prefix] <=> [$b['start'], $b['record']->prefix]);

        /* Adı olan ya da gateway'i ping alan parça kullanılıyor (bkz. GatewayPing). */
        $used = $this->union(array_filter($parts, fn (array $p): bool => $this->inUse($p['record'])));
        $free = $parts === [] ? [] : $this->layout->free(array_map(fn (array $p): array => [
            'start' => $p['start'] - $block['start'],
            'end' => $p['start'] - $block['start'] + 2 ** (32 - $p['record']->prefix) - 1,
        ], $parts));

        $rows = [
            ...array_map(fn (array $p): array => ['at' => $p['start'], ...$this->part($p['record'])], $parts),
            ...array_map(fn (array $f): array => ['at' => $block['start'] + $f['start'], ...$this->freeRow($block['start'], $f)],
                array_merge(...array_map($this->layout->cidrs(...), $free))),
        ];
        usort($rows, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $freeCount = array_sum(array_map(fn (array $f): int => $f['end'] - $f['start'] + 1, $free));

        /* Bölünmemiş blokta parça yazısı yok; açıklama sütunu kaydın açıklamasıdır. */
        $partsLabel = match (true) {
            $parts === [] => null,
            $freeCount > 0 => count($parts)." parça · {$freeCount} adres bölünmemiş",
            default => count($parts).' parça',
        };

        return [
            'record' => $block['record'],
            'notation' => long2ip($block['start']).'/24',
            'partsLabel' => $partsLabel,
            'note' => implode(' · ', array_filter([$partsLabel, $block['record']->description], filled(...))),
            /* Kullanılan adres: bölünmemiş blok tek kayıttır, kullanılıyorsa 256'sı da. */
            'used' => $parts === [] ? ($this->inUse($block['record']) ? 256 : 0) : $used,
            /*
             * Bölünmemiş blok bütünüyle tek kayıttır: adı varsa dolu, gateway'i
             * yanıt veriyorsa kullanılıyor. Bölünmüşte ad bloğun etiketidir;
             * doluluk parçalardan gelir.
             */
            ...match (true) {
                $parts === [] && filled($block['record']->name) => ['state' => 'full', 'stateLabel' => 'Dolu', 'badge' => BadgeVariant::Red],
                $parts === [] && $block['record']->gateway_up => ['state' => 'full', 'stateLabel' => 'Kullanılıyor', 'badge' => BadgeVariant::Red],
                default => $this->state($used, 256),
            },
            'pingable' => $parts === [],
            'ping' => $parts === [] ? $this->ping->of($block['record']) : null,
            'open' => ($openBlock !== null && $block['record']->uuid === $openBlock)
                || ($selected !== null && in_array($selected, array_map(fn (array $p): string => (string) $p['record']->uuid, $parts), true)),
            'parts' => $rows,
        ];
    }

    /**
     * Parça satırı: ağ geçidi bloğun ilk kullanılabilir adresi, müşteriye
     * verilen aralık ondan sonrası (ör. /29: .1 geçit, .2–.6 müşteri).
     *
     * IPv6 kaydı da bu satırla tabloda durur (bkz. IpAddressListing): yayın
     * adresi olmadığından son adres de verilir (/126: ::1 geçit, ::2–::3);
     * maske yoktur. Gateway pingi yalnız IPv4 bloklarınındır (GatewayPing).
     *
     * @return array<string, mixed>
     */
    public function part(IpAddress $record): array
    {
        $cidr = Cidr::make($record->address, $record->prefix);
        $hosts = ! $cidr->isSingleHost() && ! $cidr->isPointToPoint();

        return [
            'free' => false,
            'record' => $record,
            'notation' => $cidr->toString(),
            'gateway' => $hosts ? $cidr->firstUsable() : null,
            'first' => $hosts ? inet_ntop(Cidr::increment(inet_pton($cidr->firstUsable()))) : $cidr->firstUsable(),
            'last' => $cidr->lastUsable(),
            'mask' => $cidr->netmask(),
            'pingable' => $hosts && ! $cidr->isIpv6,
            'ping' => $this->ping->of($record),
            ...match (true) {
                filled($record->name) => ['stateLabel' => 'Dolu', 'badge' => BadgeVariant::Red],
                (bool) $record->gateway_up => ['stateLabel' => 'Kullanılıyor', 'badge' => BadgeVariant::Red],
                default => ['stateLabel' => 'Boş', 'badge' => BadgeVariant::Green],
            },
        ];
    }

    private function inUse(IpAddress $record): bool
    {
        return filled($record->name) || $record->gateway_up === true;
    }

    /**
     * Bölünmemiş alan satırı: parça değildir, bağlantısı yok; Böl ile parçalanır.
     * Her satır tek ağdır (bkz. Ipv4SplitLayout::cidrs).
     *
     * @param  array{start: int, end: int, prefix: int}  $free
     * @return array<string, mixed>
     */
    private function freeRow(int $blockStart, array $free): array
    {
        $network = long2ip($blockStart + $free['start']);

        return [
            'free' => true,
            'record' => null,
            'notation' => "{$network}/{$free['prefix']}",
            'gateway' => null,
            'first' => $network,
            'last' => long2ip($blockStart + $free['end']),
            'mask' => Cidr::make($network, $free['prefix'])->netmask(),
            'pingable' => false,
            'ping' => null,
            'name' => ($free['end'] - $free['start'] + 1).' adres',
            'stateLabel' => 'Bölünmemiş',
            'badge' => BadgeVariant::Gray,
        ];
    }

    /**
     * $space: bloğun alanı; $used: ondan atanan. IPv6 /48 başlığı parça
     * sayısıyla çağırır (bkz. IpAddressListing::tabled).
     *
     * @return array{state: string, stateLabel: string, badge: BadgeVariant}
     */
    public function state(int $used, int $space): array
    {
        return match (true) {
            $used === 0 => ['state' => 'free', 'stateLabel' => 'Boş', 'badge' => BadgeVariant::Green],
            $used >= $space => ['state' => 'full', 'stateLabel' => 'Dolu', 'badge' => BadgeVariant::Red],
            default => ['state' => 'partial', 'stateLabel' => '%'.$this->percent($used, $space).' dolu', 'badge' => BadgeVariant::Yellow],
        };
    }

    /** Parçanın bloğu: aynı grup, aynı /24. */
    private function key(IpAddress $record, int $network): string
    {
        return $record->address_group_id.'|'.$network;
    }

    /**
     * Kayıtların kapladığı adres sayısı. Bölme parçaları çakışmaz; kuraldan
     * önce girilmiş iç içe kayıtlar ikinci kez sayılmasın diye birleşim.
     *
     * @param  array<int, array{start: int, record: IpAddress}>  $entries
     */
    private function union(array $entries): int
    {
        usort($entries, fn (array $a, array $b): int => [$a['start'], $a['record']->prefix] <=> [$b['start'], $b['record']->prefix]);

        $total = 0;
        $end = -1;
        foreach ($entries as $entry) {
            if ($entry['start'] <= $end) {
                continue;
            }
            $size = 2 ** (32 - $entry['record']->prefix);
            $total += $size;
            $end = $entry['start'] + $size - 1;
        }

        return $total;
    }

    /**
     * Blokların boş payı: "%62 boşta", hiç boş yoksa "Dolu" (kırmızı yazar,
     * full); blok yoksa null (IPv6, tek adres).
     *
     * @param  iterable<array<string, mixed>>  $blocks  build()['blocks']
     * @return array{label: string, full: bool}|null
     */
    public function freeShare(iterable $blocks): ?array
    {
        $space = 0;
        $used = 0;

        foreach ($blocks as $block) {
            $space += 256;
            $used += $block['used'];
        }

        return $this->share($used, $space);
    }

    /**
     * IPv6 kayıtlarının boş payı. Adres alanı sayılamaz (tek /64 bile 2^64
     * adres); IPv4 bloğundaki parça gibi KAYIT sayılır: adı olmayan ve
     * gateway'i yanıt vermeyen kayıt boştadır. Kayıt yoksa null.
     *
     * @param  iterable<IpAddress>  $records
     * @return array{label: string, full: bool}|null
     */
    public function recordShare(iterable $records): ?array
    {
        $space = 0;
        $used = 0;

        foreach ($records as $record) {
            $space++;
            $used += $this->inUse($record) ? 1 : 0;
        }

        return $this->share($used, $space);
    }

    /** @return array{label: string, full: bool}|null */
    private function share(int $used, int $space): ?array
    {
        return match (true) {
            $space === 0 => null,
            $used >= $space => ['label' => 'Dolu', 'full' => true],
            default => ['label' => '%'.$this->percent($space - $used, $space).' boşta', 'full' => false],
        };
    }

    /** Yüzde tam sayıya yuvarlanır; ama dolu olmayan blok %100, boş olmayan %0 görünmez. */
    private function percent(int $used, int $space): int
    {
        $percent = (int) round($used * 100 / $space);

        return match (true) {
            $used > 0 && $percent === 0 => 1,
            $used < $space && $percent === 100 => 99,
            default => $percent,
        };
    }

    private function ipv4(string $address): ?int
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return (int) ip2long($address);
    }

    private function mask(int $prefix): int
    {
        return $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }
}
