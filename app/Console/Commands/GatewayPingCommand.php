<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GatewayPing;
use Illuminate\Console\Command;

/**
 * Blokların gateway'lerine ping atar, sonucu kayda yazar (bkz. GatewayPing).
 * Zamanlayıcı on dakikada bir çalıştırır; elle de çağrılabilir.
 */
class GatewayPingCommand extends Command
{
    protected $signature = 'gateways:ping';

    protected $description = "Blokların gateway'lerine ping atar, yanıt vereni kullanılıyor sayar";

    public function handle(GatewayPing $ping): int
    {
        if (! config('ip.gateway_ping.enabled')) {
            $this->info('Gateway ping kapalı (GATEWAY_PING_ENABLED).');

            return self::SUCCESS;
        }

        ['up' => $up, 'down' => $down] = $ping->run();
        $this->info("{$up} gateway yanıt verdi, {$down} vermedi.");

        return self::SUCCESS;
    }
}
