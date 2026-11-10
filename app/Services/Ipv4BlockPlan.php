<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BadgeVariant;
use App\Models\IpAddress;
use Illuminate\Support\Collection;

/**
 * IPv4 blok planı: kayıtlar /22 köklerin altında, /32'ye kadar ikiye
 * bölünen bir ağaç olarak dizilir.
 *
 * İKİ TÜR KAYIT VAR (bkz. Ipv4BlockRules): /24 ya da daha geniş kayıt
 * BLOKTUR, adres alanını tanımlar; /25–/32 kayıt bir bloğun içinden
 * yapılmış ATAMADIR. Doluluk, blok alanının ne kadarının atandığıdır.
 * Hiçbir bloğun kapsamadığı alan "kayıtsız"dır, boş değil.
 *
 * Her düğüm iki yarıya ayrılır. İçinde kayıt olmayan yarı yaprak kalır ve
 * daha fazla bölünmez: boş bir /25'in 128 adresini tek tek çizmek bilgi
 * değil gürültüdür. Ağaç yalnız kayıtlara giden yolları ve yollar boyunca
 * boşta kalan blokları gösterir.
 *
 * /22'den geniş bloklar (ör. bir /16) tek köke sığmaz; ayrı listede durur,
 * içine düşen kökler onların alanı sayılır. IPv6 bu plana girmez.
 *
 * Hizasız girilmiş blok (192.0.2.5/24) kendi ağ adresine (192.0.2.0/24)
 * oturtulur; kayıt değişmez, yalnız yeri hesaplanır.
 */
class Ipv4BlockPlan
{
    public const ROOT_PREFIX = 22;

    /**
     * @param  Collection<int, IpAddress>  $records
     * @return array{roots: list<array<string, mixed>>, wide: list<IpAddress>, skipped: int}
     */
    public function build(Collection $records): array
    {
        $entries = [];
        $wide = [];
        $skipped = 0;

        foreach ($records as $record) {
            $start = $this->ipv4($record->address);

            if ($start === null) {
                $skipped++;

                continue;
            }

            $entry = ['start' => $start & $this->mask($record->prefix), 'prefix' => $record->prefix, 'record' => $record];

            if ($record->prefix < self::ROOT_PREFIX) {
                $wide[] = $entry;
            } else {
                $entries[] = $entry;
            }
        }

        $roots = collect($entries)
            ->groupBy(fn (array $e): int => $e['start'] & $this->mask(self::ROOT_PREFIX))
            ->sortKeys()
            ->map(fn (Collection $group, int $network): array => $this->node(
                $network,
                self::ROOT_PREFIX,
                $group->all(),
                $this->coveredBy($wide, $network),
            ))
            ->values()
            ->all();

        return ['roots' => $roots, 'wide' => array_column($wide, 'record'), 'skipped' => $skipped];
    }

    /**
     * @param  list<array{start: int, prefix: int, record: IpAddress}>  $entries  Bu düğümün içindeki kayıtlar.
     * @param  bool  $inBlock  Üstteki bir blok bu düğümü kapsıyor mu.
     * @return array<string, mixed>
     */
    private function node(int $network, int $prefix, array $entries, bool $inBlock): array
    {
        $size = 2 ** (32 - $prefix);
        $own = array_values(array_filter($entries, fn (array $e): bool => $e['prefix'] === $prefix));
        $inner = array_values(array_filter($entries, fn (array $e): bool => $e['prefix'] > $prefix));

        $inBlock = $inBlock || array_filter($own, fn (array $e): bool => $this->isBlock($e)) !== [];
        $space = $inBlock ? $size : $this->union(array_filter($inner, fn (array $e): bool => $this->isBlock($e)));
        $used = $this->union(array_filter($entries, fn (array $e): bool => ! $this->isBlock($e)));

        $children = [];
        if ($inner !== [] && $prefix < 32) {
            $half = $size / 2;
            foreach ([$network, $network + $half] as $start) {
                $inside = array_values(array_filter($inner, fn (array $e): bool => $e['start'] >= $start && $e['start'] < $start + $half));
                $children[] = $inside === []
                    ? $this->leaf($start, $prefix + 1, $inBlock)
                    : $this->node($start, $prefix + 1, $inside, $inBlock);
            }
        }

        return [
            ...$this->base($network, $prefix),
            'space' => $space,
            'used' => $used,
            ...$this->state($used, $space),
            'records' => array_map(fn (array $e): IpAddress => $e['record'], $own),
            'children' => $children,
        ];
    }

    /** @return array<string, mixed> */
    private function leaf(int $network, int $prefix, bool $inBlock): array
    {
        $space = $inBlock ? 2 ** (32 - $prefix) : 0;

        return [
            ...$this->base($network, $prefix),
            'space' => $space,
            'used' => 0,
            ...$this->state(0, $space),
            'records' => [],
            'children' => [],
        ];
    }

    /** @return array{notation: string, prefix: int, size: int, sizeLabel: string} */
    private function base(int $network, int $prefix): array
    {
        $size = 2 ** (32 - $prefix);

        return [
            'notation' => long2ip($network).'/'.$prefix,
            'prefix' => $prefix,
            'size' => $size,
            'sizeLabel' => number_format($size, 0, ',', '.').' adres',
        ];
    }

    /**
     * $space: düğümde blokların tanımladığı alan; $used: ondan atanan.
     *
     * @return array{state: string, stateLabel: string, badge: BadgeVariant}
     */
    private function state(int $used, int $space): array
    {
        return match (true) {
            $space === 0 => ['state' => 'unregistered', 'stateLabel' => 'Kayıtsız', 'badge' => BadgeVariant::Gray],
            $used === 0 => ['state' => 'free', 'stateLabel' => 'Boş', 'badge' => BadgeVariant::Green],
            $used >= $space => ['state' => 'full', 'stateLabel' => 'Dolu', 'badge' => BadgeVariant::Red],
            default => ['state' => 'partial', 'stateLabel' => '%'.$this->percent($used, $space).' dolu', 'badge' => BadgeVariant::Yellow],
        };
    }

    /** @param  array{prefix: int}  $entry */
    private function isBlock(array $entry): bool
    {
        return $entry['prefix'] <= Ipv4BlockRules::BLOCK_PREFIX;
    }

    /** @param  list<array{start: int, prefix: int}>  $wide */
    private function coveredBy(array $wide, int $network): bool
    {
        foreach ($wide as $block) {
            if (($network & $this->mask($block['prefix'])) === $block['start']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kayıtların kapladığı adres sayısı. CIDR blokları ya iç içedir ya
     * ayrık; başka bir kaydın içinde kalan kayıt ikinci kez sayılmaz.
     *
     * @param  array<int, array{start: int, prefix: int}>  $entries
     */
    private function union(array $entries): int
    {
        usort($entries, fn (array $a, array $b): int => [$a['start'], $a['prefix']] <=> [$b['start'], $b['prefix']]);

        $total = 0;
        $end = -1;
        foreach ($entries as $entry) {
            if ($entry['start'] <= $end) {
                continue;
            }
            $size = 2 ** (32 - $entry['prefix']);
            $total += $size;
            $end = $entry['start'] + $size - 1;
        }

        return $total;
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
