<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\GatewayPing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bir paket gateway'in kontrolü; paket aynı anda pinglenir, sonuç kayda
 * yazılır (bkz. GatewayPing::checkMany). Tur paketleri varsayılan kuyruğa
 * atar; ↻ tek gateway'lik paketi NOW kuyruğuna, işçi önce onu işler
 * (supervisord.conf, --queue=hemen,default).
 *
 * Kimlik model değil düz sayıdır: arada silinen kayıtta güncelleme boşa
 * düşer, iş hata vermez. Tekrar denenmez; sonraki tur yeniden dener.
 */
class PingGatewayJob implements ShouldQueue
{
    use Queueable;

    /** ↻'nin kuyruğu: işçi bunu turdan önce işler. */
    public const NOW = 'hemen';

    public int $tries = 1;

    public int $timeout = 60;

    /** @param  list<array{id: int, gateway: string}>  $targets */
    public function __construct(public array $targets) {}

    public function handle(GatewayPing $ping): void
    {
        $ping->checkMany($this->targets);
    }
}
