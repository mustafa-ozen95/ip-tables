<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GatewayPing;
use Illuminate\Console\Command;

/**
 * Gateway ping turunu başlatır: bu turu hemen kuyruğa atar, sonrakini kurar
 * (bkz. GatewayPingRoundJob). Konteyner açılışında çalışır; tur ondan sonra
 * kendini kurar. Elle de çağrılabilir.
 */
class GatewayPingCommand extends Command
{
    protected $signature = 'gateways:ping';

    protected $description = 'Gateway ping turunu kuyruğa alır (yanıt veren kullanılıyor sayılır)';

    public function handle(GatewayPing $ping): int
    {
        if (! config('ip.gateway_ping.enabled')) {
            $this->info('Gateway ping kapalı (GATEWAY_PING_ENABLED).');

            return self::SUCCESS;
        }

        $count = $ping->round();
        $ping->scheduleNextRound();
        $this->info("{$count} gateway için ping kuyruğa alındı; sonraki tur kuruldu.");

        return self::SUCCESS;
    }
}
