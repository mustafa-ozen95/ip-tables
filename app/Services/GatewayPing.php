<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IpAddress;
use App\Support\Net\Cidr;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Blokların gateway'lerine ping (bkz. config/ip.php · gateway_ping).
 *
 * Hedef bloğa göre seçilir: bölünmüş blokta her parçanın gateway'i (ağ
 * adresi + 1; /31 ve /32'de gateway yok), bölünmemiş /24'te .1. Bölünmemiş
 * boş alana ping atılmaz. Yanıt veren gateway'in kaydı "kullanılıyor"
 * sayılır (bkz. Ipv4BlockPlan).
 *
 * Komut kabuktan geçmez (dizi); adres ip2long/long2ip'ten gelir. Sonuç kayda
 * sessizce yazılır: updated_at değişmez, etkinlik kaydı düşmez.
 */
class GatewayPing
{
    public function __construct(private Ipv4BlockPlan $plan) {}

    /** @return list<array{record: IpAddress, gateway: string}> */
    public function targets(): array
    {
        $records = IpAddress::query()->ordered()->get();
        $targets = [];

        foreach ($this->plan->build($records)['blocks'] as $block) {
            if ($block['parts'] === []) {
                $targets[] = ['record' => $block['record'], 'gateway' => Cidr::make($block['record']->address, Ipv4BlockRules::BLOCK_PREFIX)->firstUsable()];

                continue;
            }

            foreach ($block['parts'] as $part) {
                if (! $part['free'] && $part['gateway'] !== null) {
                    $targets[] = ['record' => $part['record'], 'gateway' => $part['gateway']];
                }
            }
        }

        return $targets;
    }

    /** @return array{up: int, down: int} */
    public function run(): array
    {
        $results = [];

        foreach (array_chunk($this->targets(), max(1, (int) config('ip.gateway_ping.concurrency'))) as $chunk) {
            $pool = Process::pool(function (Pool $pool) use ($chunk): void {
                foreach ($chunk as $i => $target) {
                    $pool->as((string) $i)->timeout($this->timeout() + 5)->command($this->command($target['gateway']));
                }
            })->start()->wait();

            foreach ($chunk as $i => $target) {
                $results[$target['record']->getKey()] = $pool[(string) $i]->successful();
            }
        }

        $this->store($results);

        $up = count(array_filter($results));

        return ['up' => $up, 'down' => count($results) - $up];
    }

    /** @return list<string> */
    private function command(string $gateway): array
    {
        return [(string) config('ip.gateway_ping.binary'), '-n', '-c', '1', '-W', (string) $this->timeout(), $gateway];
    }

    private function timeout(): int
    {
        return max(1, (int) config('ip.gateway_ping.timeout'));
    }

    /**
     * Önce bütün sonuçlar düşer, sonra bu turun sonuçları yazılır: artık
     * hedef olmayan kaydın (ör. sonradan bölünen blok) eski noktası kalmasın.
     * Son yanıt zamanı (gateway_seen_at) korunur. Tek işlemde: arada boş
     * ekran görülmez.
     *
     * @param  array<int, bool>  $results  kayıt kimliği => yanıt verdi mi
     */
    private function store(array $results): void
    {
        $now = now();
        $up = array_keys(array_filter($results));
        $down = array_keys(array_filter($results, fn (bool $ok): bool => ! $ok));

        DB::transaction(function () use ($now, $up, $down): void {
            IpAddress::query()->whereNotNull('gateway_checked_at')->toBase()
                ->update(['gateway_up' => null, 'gateway_checked_at' => null]);

            foreach (array_chunk($up, 500) as $ids) {
                IpAddress::query()->whereKey($ids)->toBase()
                    ->update(['gateway_up' => true, 'gateway_checked_at' => $now, 'gateway_seen_at' => $now]);
            }

            foreach (array_chunk($down, 500) as $ids) {
                IpAddress::query()->whereKey($ids)->toBase()
                    ->update(['gateway_up' => false, 'gateway_checked_at' => $now]);
            }
        });
    }
}
