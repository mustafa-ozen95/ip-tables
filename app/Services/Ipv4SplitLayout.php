<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Net\Cidr;

/**
 * Bir /24 bloğun bölme düzeni: parça boyları, bloğun başından SIRAYLA.
 *
 * Yazım: "29x10 28 28 29x2 27" — boşluk ya da virgülle ayrılır, "/" isteğe
 * bağlı, "x10" (× ve * de olur) aynı boyu tekrarlar. Sıra kullanıcınındır:
 * on /29'dan sonra .80'de bir /28 gelebilir. Bu yüzden boylar en büyükten
 * dizilmez, yazıldığı gibi yerleşir ve her parça kendi sınırında başlamak
 * zorundadır (/28 ancak 16'nın katında).
 *
 * Parçalar bloğu TAM doldurur; boşta kalan alan bölünmemiş alan olurdu ve
 * bölme tek kat olduğu için oraya sonradan parça açılamazdı. Bu yüzden
 * yazılan SON BOY bloğun sonuna kadar tekrarlanır: yalnız "29" 32 tane /29,
 * "28x2 29" iki /28 ve kalanı /29 demektir (bkz. complete).
 */
class Ipv4SplitLayout
{
    public const MIN_PREFIX = Ipv4BlockRules::BLOCK_PREFIX + 1;

    public const MAX_PREFIX = 32;

    private const BLOCK_SIZE = 2 ** (32 - Ipv4BlockRules::BLOCK_PREFIX);

    /**
     * Yazımı parça önek listesine açar; yazım bozuksa null.
     *
     * @return list<int>|null
     */
    public function parse(string $input): ?array
    {
        $tokens = preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        $prefixes = [];

        foreach ($tokens as $token) {
            if (! preg_match('~^/?(\d{1,2})(?:[x×*](\d{1,3}))?$~iu', $token, $m)) {
                return null;
            }

            $count = (int) ($m[2] ?? 1);

            /* Tekrar sayısı bloğu zaten aşıyorsa açmaya gerek yok; uzunluğu da sınırlar. */
            if ($count < 1 || count($prefixes) + $count > self::BLOCK_SIZE) {
                return null;
            }

            array_push($prefixes, ...array_fill(0, $count, (int) $m[1]));
        }

        return $prefixes === [] ? null : $prefixes;
    }

    /**
     * Son boyu bloğun sonuna kadar tekrarlar. Son parça kendi sınırında
     * başladıysa kalan alan onun katıdır; başlamadıysa error() yakalar.
     *
     * @param  list<int>  $prefixes
     * @return list<int>
     */
    public function complete(array $prefixes): array
    {
        $last = end($prefixes);

        if ($last < self::MIN_PREFIX || $last > self::MAX_PREFIX) {
            return $prefixes;
        }

        $total = array_sum(array_map(fn (int $p): int => $this->size($p), $prefixes));

        while ($total + $this->size($last) <= self::BLOCK_SIZE) {
            $prefixes[] = $last;
            $total += $this->size($last);
        }

        return $prefixes;
    }

    /** @param  list<int>  $prefixes */
    public function error(string $network, array $prefixes): ?string
    {
        $offset = 0;

        foreach ($prefixes as $prefix) {
            if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX) {
                return 'Parça boyu /'.self::MIN_PREFIX.' ile /'.self::MAX_PREFIX." arasında olmalı; /{$prefix} olmaz.";
            }

            $size = 2 ** (32 - $prefix);

            if ($offset + $size > self::BLOCK_SIZE) {
                return 'Parçalar /'.Ipv4BlockRules::BLOCK_PREFIX.'’ü aşıyor: toplam '
                    .($offset + $size).' adres, blok '.self::BLOCK_SIZE.' adres.';
            }

            if ($offset % $size !== 0) {
                return "/{$prefix} parçası {$size} adreslik sınırda başlamalı; "
                    .$this->address($network, $offset).' uygun değil. Parçaların sırasını değiştirin.';
            }

            $offset += $size;
        }

        return $offset === self::BLOCK_SIZE ? null
            : "Parçalar {$offset} adres ediyor; blok tam ".self::BLOCK_SIZE.' adres olmalı (eksik '
                .(self::BLOCK_SIZE - $offset).').';
    }

    /**
     * Doğrulanmış düzenin parçaları: "192.0.2.0/29", "192.0.2.8/29", …
     *
     * @param  list<int>  $prefixes
     * @return list<string>
     */
    public function parts(string $network, array $prefixes): array
    {
        $offset = 0;
        $parts = [];

        foreach ($prefixes as $prefix) {
            $parts[] = $this->address($network, $offset)."/{$prefix}";
            $offset += 2 ** (32 - $prefix);
        }

        return $parts;
    }

    /**
     * Kayda yazılan kısa düzen: ardışık aynı boylar birleşir ("29×10 28×2 27").
     *
     * @param  list<int>  $prefixes
     */
    public function describe(array $prefixes): string
    {
        $runs = [];

        foreach ($prefixes as $prefix) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] === $prefix) {
                $runs[$last][1]++;
            } else {
                $runs[] = [$prefix, 1];
            }
        }

        return implode(' ', array_map(
            fn (array $run): string => "/{$run[0]}".($run[1] > 1 ? "×{$run[1]}" : ''),
            $runs,
        ));
    }

    private function size(int $prefix): int
    {
        return 2 ** (32 - $prefix);
    }

    private function address(string $network, int $offset): string
    {
        $base = Cidr::make($network, Ipv4BlockRules::BLOCK_PREFIX)->network;

        return long2ip(ip2long($base) + $offset);
    }
}
