<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\GatewayPing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tek gateway'e tek ping; sonuç kayda yazılır (bkz. GatewayPing::check).
 * Tur bunları beş dakikaya yayarak kuyruğa atar, ↻ ise hemen atar.
 *
 * Kimlik model değil düz sayıdır: arada silinen kayıtta güncelleme boşa
 * düşer, iş hata vermez. Tekrar denenmez; sonraki tur yeniden dener.
 */
class PingGatewayJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $recordId, public string $gateway) {}

    public function handle(GatewayPing $ping): void
    {
        $ping->check($this->recordId, $this->gateway);
    }
}
