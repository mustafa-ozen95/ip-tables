<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Net\Cidr;

/**
 * Bir /24 bloğun bölme aralıkları. Her aralık bloğun içinde bir başlangıç,
 * bir bitiş ve bir boydur: ".0–.63 /29" sekiz tane /29 demektir. Pencerede
 * önce boy, sonra kaç parça, en son o kadar parçanın sığdığı boş aralık
 * seçilir (ranges); blok bir kerede tam bölünmek zorunda değildir.
 *
 * Konumlar blok içi SIRADIR (0–255), adres değil: blok hizasız girilmiş
 * olsa da (192.0.2.5/24) hesap ağ adresinden yapılır.
 *
 * Seçilmeyen alan BOŞ ALAN olarak kalır ve sonra aynı pencereyle bölünür.
 * Bölme yine tek kattır: aralık var olan bir parçayla çakışamaz; parçanın
 * boyu yalnız parçalama ve birleştirmeyle değişir (bkz. Ipv4BlockRules).
 */
class Ipv4SplitLayout
{
    public const MIN_PREFIX = Ipv4BlockRules::BLOCK_PREFIX + 1;

    public const MAX_PREFIX = 32;

    /**
     * Pencerede seçilebilen en küçük boy. /31 ve /32 tek tek seçilirse liste
     * yüzlerce satır olurdu; onlar parça parçalanarak açılır.
     */
    public const SPLIT_MAX_PREFIX = 30;

    public const BLOCK_SIZE = 2 ** (32 - Ipv4BlockRules::BLOCK_PREFIX);

    /** Bir istekte en çok bu kadar aralık (pencere tek satır gönderir). */
    public const MAX_RANGES = 8;

    /**
     * $taken: var olan parçaların kapladığı aralıklar.
     *
     * @param  list<array{start: int, end: int, prefix: int}>  $ranges
     * @param  list<array{start: int, end: int}>  $taken
     */
    public function error(string $network, array $ranges, array $taken): ?string
    {
        foreach ($ranges as $range) {
            $error = $this->rangeError($network, $range);

            if ($error !== null) {
                return $error;
            }
        }

        $spans = [...array_map(fn (array $r): array => [...$r, 'part' => false], $ranges),
            ...array_map(fn (array $t): array => [...$t, 'part' => true], $taken)];
        usort($spans, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        for ($i = 1; $i < count($spans); $i++) {
            [$a, $b] = [$spans[$i - 1], $spans[$i]];

            if ($b['start'] <= $a['end']) {
                $first = $a['part'] ? $b : $a;

                return $a['part'] || $b['part']
                    ? $this->label($network, $first).' aralığı bölünmüş bir yerle çakışıyor; yalnız boş alan bölünür.'
                    : $this->label($network, $a).' ile '.$this->label($network, $b).' aralıkları çakışıyor.';
            }
        }

        return null;
    }

    /**
     * Aralıkların parçaları: "192.0.2.0/29", "192.0.2.8/29", …
     *
     * @param  list<array{start: int, end: int, prefix: int}>  $ranges
     * @return list<string>
     */
    public function parts(string $network, array $ranges): array
    {
        $parts = [];

        foreach ($ranges as $range) {
            for ($at = $range['start']; $at <= $range['end']; $at += $this->size($range['prefix'])) {
                $parts[] = $this->address($network, $at)."/{$range['prefix']}";
            }
        }

        return $parts;
    }

    /**
     * Kayda yazılan kısa düzen: ".0–.63 /29×8 · .64–.127 /28×4".
     *
     * @param  list<array{start: int, end: int, prefix: int}>  $ranges
     */
    public function describe(array $ranges): string
    {
        return implode(' · ', array_map(function (array $r): string {
            $count = intdiv($r['end'] - $r['start'] + 1, $this->size($r['prefix']));

            return ".{$r['start']}–.{$r['end']} /{$r['prefix']}".($count > 1 ? "×{$count}" : '');
        }, $ranges));
    }

    /**
     * Aralığı kendi sınırında başlayan en büyük ağlara böler: .132–.143 →
     * .132/30 + .136/29. Bölünmemiş alan aralık değil ağ olarak gösterilir.
     *
     * @param  array{start: int, end: int}  $range
     * @return list<array{start: int, end: int, prefix: int}>
     */
    public function cidrs(array $range): array
    {
        $pieces = [];

        for ($start = $range['start']; $start <= $range['end']; $start += $size) {
            $size = $start === 0 ? self::BLOCK_SIZE : $start & -$start;

            while ($start + $size - 1 > $range['end']) {
                $size = intdiv($size, 2);
            }

            $pieces[] = ['start' => $start, 'end' => $start + $size - 1, 'prefix' => 32 - (int) log($size, 2)];
        }

        return $pieces;
    }

    /**
     * Bölünmemiş alan: dolu aralıkların arasında kalanlar.
     *
     * @param  list<array{start: int, end: int}>  $taken
     * @return list<array{start: int, end: int}>
     */
    public function free(array $taken): array
    {
        usort($taken, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $free = [];
        $next = 0;

        foreach ($taken as $span) {
            if ($span['start'] > $next) {
                $free[] = ['start' => $next, 'end' => $span['start'] - 1];
            }
            $next = max($next, $span['end'] + 1);
        }

        if ($next < self::BLOCK_SIZE) {
            $free[] = ['start' => $next, 'end' => self::BLOCK_SIZE - 1];
        }

        return $free;
    }

    /**
     * Boş alanda bu boyda açılabilecek yerler: kendi sınırında başlayan ve
     * tamamı boş alana düşen parçaların başlangıçları.
     *
     * @param  list<array{start: int, end: int}>  $free
     * @return list<int>
     */
    public function slots(array $free, int $prefix): array
    {
        return array_merge(...array_map(
            fn (array $run): array => range($run['start'], $run['start'] + ($run['count'] - 1) * $this->size($prefix), $this->size($prefix)),
            $this->runs($free, $prefix),
        ) ?: [[]]);
    }

    /**
     * Art arda $count parçanın sığdığı aralıkların başlangıçları. Her boş
     * bölüm baştan $count'luk dilimlere ayrılır: liste kısa kalır, araya
     * kayan seçenekler (ör. iki /29'u .8'den başlatmak) tek parçayla açılır.
     *
     * @param  list<array{start: int, end: int}>  $free
     * @return list<int>
     */
    public function ranges(array $free, int $prefix, int $count): array
    {
        $starts = [];

        foreach ($this->runs($free, $prefix) as $run) {
            for ($i = 0; $i + $count <= $run['count']; $i += $count) {
                $starts[] = $run['start'] + $i * $this->size($prefix);
            }
        }

        return $starts;
    }

    /**
     * Bu boyda art arda kaç parça sığar: en uzun boş bölümün parça sayısı.
     *
     * @param  list<array{start: int, end: int}>  $free
     */
    public function longestRun(array $free, int $prefix): int
    {
        return max([0, ...array_column($this->runs($free, $prefix), 'count')]);
    }

    /**
     * Boş bölümlerde bu boyun art arda dizilebildiği yerler: ilk hizalı
     * başlangıç ve sığan parça sayısı.
     *
     * @param  list<array{start: int, end: int}>  $free
     * @return list<array{start: int, count: int}>
     */
    private function runs(array $free, int $prefix): array
    {
        $size = $this->size($prefix);
        $runs = [];

        foreach ($free as $span) {
            $start = intdiv($span['start'] + $size - 1, $size) * $size;
            $count = intdiv($span['end'] + 1 - $start, $size);

            if ($count > 0) {
                $runs[] = ['start' => $start, 'count' => $count];
            }
        }

        return $runs;
    }

    /** Konumun adresi: blok ağ adresi + sıra. */
    public function address(string $network, int $offset): string
    {
        return long2ip(ip2long($this->base($network)) + $offset);
    }

    public function size(int $prefix): int
    {
        return 2 ** (32 - $prefix);
    }

    /** @param  array{start: int, end: int, prefix: int}  $range */
    private function rangeError(string $network, array $range): ?string
    {
        ['start' => $start, 'end' => $end, 'prefix' => $prefix] = $range;

        if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX) {
            return 'Subnet /'.self::MIN_PREFIX.' ile /'.self::MAX_PREFIX." arasında olmalı; /{$prefix} olmaz.";
        }

        if ($end < $start) {
            return 'Bitiş başlangıçtan önce olamaz: '.$this->label($network, $range).'.';
        }

        $size = $this->size($prefix);

        if ($start % $size !== 0) {
            return "/{$prefix} {$size} adreslik sınırda başlar; "
                .$this->address($network, $start).' uygun değil (ör. .'.($start - $start % $size).' ya da .'.($start - $start % $size + $size).').';
        }

        if (($end - $start + 1) % $size !== 0) {
            return $this->label($network, $range)." aralığı {$size}’in katı değil; /{$prefix} ile tam bölünmüyor"
                .' (bitiş ör. .'.($start + max($size, intdiv($end - $start + 1, $size) * $size) - 1).').';
        }

        return null;
    }

    /** @param  array{start: int, end: int}  $range */
    private function label(string $network, array $range): string
    {
        return $this->address($network, $range['start']).' – .'.$range['end'];
    }

    private function base(string $network): string
    {
        return Cidr::make($network, Ipv4BlockRules::BLOCK_PREFIX)->network;
    }
}
