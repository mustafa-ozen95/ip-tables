<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\GatewayPing;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gateway ping turu: zamanlayıcı yok, tur kendini kurar. Her tur bütün
 * hedefleri yayarak kuyruğa atar (bkz. GatewayPing::round) ve bir sonraki
 * turu config('ip.gateway_ping.interval') sonrasına koyar.
 *
 * Kuyrukta bekleyen tek tur olur: kilit iş başlayınca bırakılır ki tur
 * kendini yeniden kurabilsin; açılışta ya da ↻ ile kurulmak istenen ikinci
 * tur, bekleyen varken kuyruğa girmez.
 */
class GatewayPingRoundJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** Kilit bekleyen turdan uzun yaşar; redis giderse kilit de gider, tur yeniden kurulabilir. */
    public function uniqueFor(): int
    {
        return (int) config('ip.gateway_ping.interval') + 300;
    }

    public function handle(GatewayPing $ping): void
    {
        if (! config('ip.gateway_ping.enabled')) {
            return;
        }

        $ping->round();
        $ping->scheduleNextRound();
    }
}
