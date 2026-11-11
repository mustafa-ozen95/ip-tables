<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PingGatewayJob;
use App\Models\IpAddress;
use App\Services\GatewayPing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ↻ şimdi kontrol et: kaydın gateway'ine hemen bir ping kuyruğa atılır.
 * Dışarıya paket çıkardığı için "görüntüle" yetmez, ayrı "ping" yetkisi
 * ister (bkz. config/permissions.php). Tur kuyruktan düşmüşse yeniden kurulur.
 */
class GatewayPingController extends Controller implements HasMiddleware
{
    public function __construct(private GatewayPing $ping) {}

    public static function middleware(): array
    {
        return [new Middleware('can:ping,ipAddress')];
    }

    public function store(IpAddress $ipAddress): RedirectResponse
    {
        $gateway = config('ip.gateway_ping.enabled') ? $this->ping->gatewayOf($ipAddress) : null;

        if ($gateway === null) {
            return back()->with('error', config('ip.gateway_ping.enabled')
                ? "{$ipAddress->notation} için pinglenecek gateway yok."
                : 'Gateway ping kapalı.');
        }

        PingGatewayJob::dispatch($ipAddress->getKey(), $gateway);
        $this->ping->scheduleNextRound();

        return back()->with('status', "{$gateway} için ping kuyruğa alındı; sonuç birkaç saniye içinde noktada görünür.");
    }
}
