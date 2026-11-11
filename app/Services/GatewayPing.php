<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GatewayPingRoundJob;
use App\Jobs\PingGatewayJob;
use App\Models\IpAddress;
use App\Support\Net\Cidr;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/**
 * Blokların gateway'lerine ping (bkz. config/ip.php · gateway_ping).
 *
 * Hedef bloğa göre seçilir: bölünmüş blokta her parçanın gateway'i (ağ
 * adresi + 1; /31 ve /32'de gateway yok), bölünmemiş /24'te .1. Bölünmemiş
 * boş alana ping atılmaz. Yanıt veren gateway'in kaydı "kullanılıyor"
 * sayılır (bkz. Ipv4BlockPlan).
 *
 * Pingler KUYRUKTAN gider: tur hedefleri `concurrency`'lik paketlere böler,
 * her paket bir iştir ve paketteki gateway'ler AYNI ANDA pinglenir (ayrı
 * süreçler); paketler sırayla işlenir (bkz. GatewayPingRoundJob). Gateway
 * başına `count` ping saniyede bir gider; biri yanıt alırsa gateway yanıt
 * veriyor sayılır. ↻ ayrı, önce işlenen kuyruktadır (PingGatewayJob::NOW):
 * en çok bir paket bekler.
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

    /** Kaydın pinglenen gateway'i; hedef değilse null. */
    public function gatewayOf(IpAddress $record): ?string
    {
        foreach ($this->targets() as $target) {
            if ($target['record']->is($record)) {
                return $target['gateway'];
            }
        }

        return null;
    }

    /**
     * Bir tur: hedefleri paketler hâlinde kuyruğa atar. Artık hedef olmayan kaydın
     * (ör. sonradan bölünen blok) eski sonucu düşer; son yanıt zamanı
     * (gateway_seen_at) korunur.
     *
     * @return int kuyruğa atılan ping sayısı
     */
    public function round(): int
    {
        $targets = $this->targets();

        IpAddress::query()->whereNotNull('gateway_checked_at')
            ->whereKeyNot(array_map(fn (array $t): int => $t['record']->getKey(), $targets))
            ->toBase()->update(['gateway_up' => null, 'gateway_checked_at' => null]);

        $jobs = array_map(fn (array $t): array => ['id' => $t['record']->getKey(), 'gateway' => $t['gateway']], $targets);

        foreach (array_chunk($jobs, $this->concurrency()) as $batch) {
            PingGatewayJob::dispatch($batch);
        }

        return count($targets);
    }

    /**
     * Sonraki tur; bekleyen tur varsa kuyruğa ikincisi girmez. Vakti gelen
     * tur kuyruğun sonuna girer: önceki turun pingleri bitmeden başlamaz.
     */
    public function scheduleNextRound(): void
    {
        GatewayPingRoundJob::dispatch()->delay(now()->addSeconds(self::intervalSeconds()));
    }

    /** Tur aralığı; ayar boş ya da sıfırsa bir dakika. */
    public static function intervalSeconds(): int
    {
        return max(1, (int) config('ip.gateway_ping.interval_minutes')) * 60;
    }

    /** Tek gateway'in kontrolü (bkz. checkMany). */
    public function check(int $recordId, string $gateway): bool
    {
        return $this->checkMany([['id' => $recordId, 'gateway' => $gateway]])[$recordId];
    }

    /**
     * Paketteki gateway'ler aynı anda pinglenir; sonuçlar kayda yazılır.
     * Kayıt arada silindiyse yazılacak satır yoktur.
     *
     * @param  list<array{id: int, gateway: string}>  $targets
     * @return array<int, bool> kayıt kimliği => yanıt verdi mi
     */
    public function checkMany(array $targets): array
    {
        $results = Process::pool(function (Pool $pool) use ($targets): void {
            foreach ($targets as $target) {
                $pool->timeout($this->count() + $this->timeout() + 5)->command($this->command($target['gateway']));
            }
        })->start()->wait();

        $now = now();
        $up = [];

        foreach ($targets as $i => $target) {
            $up[$target['id']] = $results[$i]->successful();

            IpAddress::query()->whereKey($target['id'])->toBase()->update($up[$target['id']]
                ? ['gateway_up' => true, 'gateway_checked_at' => $now, 'gateway_seen_at' => $now]
                : ['gateway_up' => false, 'gateway_checked_at' => $now]);
        }

        return $up;
    }

    /** @return list<string> */
    private function command(string $gateway): array
    {
        /* -w: ping kendi süresini aşmaz; süreç zaman aşımına kalmadan biter. */
        return [(string) config('ip.gateway_ping.binary'), '-n', '-c', (string) $this->count(), '-i', '1',
            '-W', (string) $this->timeout(), '-w', (string) ($this->count() + $this->timeout()), $gateway];
    }

    /** Aynı anda pinglenen gateway sayısı (paket boyu). */
    private function concurrency(): int
    {
        return min(50, max(1, (int) config('ip.gateway_ping.concurrency')));
    }

    private function count(): int
    {
        return min(10, max(1, (int) config('ip.gateway_ping.count')));
    }

    private function timeout(): int
    {
        return max(1, (int) config('ip.gateway_ping.timeout'));
    }
}
