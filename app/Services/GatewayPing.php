<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GatewayPingRoundJob;
use App\Jobs\PingGatewayJob;
use App\Models\IpAddress;
use App\Support\Net\Cidr;
use Illuminate\Support\Facades\Process;

/**
 * Blokların gateway'lerine ping (bkz. config/ip.php · gateway_ping).
 *
 * Hedef bloğa göre seçilir: bölünmüş blokta her parçanın gateway'i (ağ
 * adresi + 1; /31 ve /32'de gateway yok), bölünmemiş /24'te .1. Bölünmemiş
 * boş alana ping atılmaz. Yanıt veren gateway'in kaydı "kullanılıyor"
 * sayılır (bkz. Ipv4BlockPlan).
 *
 * Pingler KUYRUKTAN gider, hepsi bir anda değil: tur her hedef için bir iş
 * atar, işler `spread` saniyeye eşit aralıkla yayılır; kuyruk işçisi tek
 * olduğundan aynı anda tek ping çıkar (bkz. GatewayPingRoundJob).
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
     * Bir tur: hedefleri yayarak kuyruğa atar. Artık hedef olmayan kaydın
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

        $gap = intdiv(max(0, (int) config('ip.gateway_ping.spread')) * 1000, max(1, count($targets)));

        foreach ($targets as $i => $target) {
            PingGatewayJob::dispatch($target['record']->getKey(), $target['gateway'])
                ->delay(now()->addMilliseconds($i * $gap));
        }

        return count($targets);
    }

    /** Sonraki tur; bekleyen tur varsa kuyruğa ikincisi girmez. */
    public function scheduleNextRound(): void
    {
        GatewayPingRoundJob::dispatch()->delay(now()->addSeconds((int) config('ip.gateway_ping.interval')));
    }

    /** Tek ping; sonuç kayda yazılır. Kayıt arada silindiyse yazılacak satır yoktur. */
    public function check(int $recordId, string $gateway): bool
    {
        $up = Process::timeout($this->timeout() + 5)->run($this->command($gateway))->successful();
        $now = now();

        IpAddress::query()->whereKey($recordId)->toBase()->update($up
            ? ['gateway_up' => true, 'gateway_checked_at' => $now, 'gateway_seen_at' => $now]
            : ['gateway_up' => false, 'gateway_checked_at' => $now]);

        return $up;
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
}
