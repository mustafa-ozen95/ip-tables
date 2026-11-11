<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PingGatewayJob;
use App\Models\IpAddress;
use App\Services\GatewayPing;
use App\Services\GatewayPingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ↻ şimdi kontrol et: kaydın gateway'ine hemen bir ping kuyruğa atılır.
 * Dışarıya paket çıkardığı için "görüntüle" yetmez, ayrı "ping" yetkisi
 * ister (bkz. config/permissions.php). Tur kuyruktan düşmüşse yeniden kurulur.
 *
 * Betik (gateway-ping.js) isteği JSON ile atar, sonucu show() ile bekler:
 * ikon sonuç gelene dek döner. Betiksiz gönderim listeye geri döner.
 */
class GatewayPingController extends Controller implements HasMiddleware
{
    public function __construct(private GatewayPing $ping, private GatewayPingStatus $status) {}

    public static function middleware(): array
    {
        return [new Middleware('can:ping,ipAddress')];
    }

    public function store(Request $request, IpAddress $ipAddress): RedirectResponse|JsonResponse
    {
        $gateway = config('ip.gateway_ping.enabled') ? $this->ping->gatewayOf($ipAddress) : null;

        if ($gateway === null) {
            $message = config('ip.gateway_ping.enabled') ? "{$ipAddress->notation} için pinglenecek gateway yok." : 'Gateway ping kapalı.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->with('error', $message);
        }

        PingGatewayJob::dispatch($ipAddress->getKey(), $gateway);
        $this->ping->scheduleNextRound();
        $message = "{$gateway} için ping kuyruğa alındı; sonuç birkaç saniye içinde noktada görünür.";

        /* checkedAt: betik bundan farklı bir zaman görünce sonucun geldiğini anlar. */
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'checkedAt' => $ipAddress->gateway_checked_at?->toIso8601String()])
            : back()->with('status', $message);
    }

    /** Son ping sonucu; henüz pinglenmediyse checkedAt boştur. */
    public function show(IpAddress $ipAddress): JsonResponse
    {
        $status = $this->status->of($ipAddress);

        return response()->json([
            'checkedAt' => $ipAddress->gateway_checked_at?->toIso8601String(),
            'variant' => $status['variant']->value ?? null,
            'label' => $status['label'] ?? null,
        ]);
    }
}
