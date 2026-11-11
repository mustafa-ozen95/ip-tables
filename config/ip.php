<?php

declare(strict_types=1);

return [

    /*
    | Yerel sayılan aralıklar. Adresin türü buradan belirlenir:
    | bu aralıklardan birindeyse "Local IPv4/IPv6", değilse "IPv4/IPv6"
    | türündeki gruplara girer (bkz. App\Enums\GroupKind).
    |
    | RFC 1918, döngü, bağlantı-yerel, CGNAT (RFC 6598) ve IPv6 ULA. Liste
    | IP_LOCAL_RANGES ile (virgülle) değiştirilebilir; verilirse bu liste
    | yerine geçer.
    */
    /* Boş değer (IP_LOCAL_RANGES=) de varsayılana düşer: boş liste "hiçbir adres yerel değil" olurdu. */
    'local_ranges' => array_values(array_filter(array_map('trim', explode(',', (string) (env('IP_LOCAL_RANGES')
        ?: '0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,'
        .'192.168.0.0/16,::/128,::1/128,fc00::/7,fe80::/10'))))),

    /*
    | Gateway ping (bkz. App\Services\GatewayPing). Kuyruktaki tur her
    | `interval` saniyede blokların gateway'lerine birer ping atar: bölünmüş
    | blokta her parçanın gateway'i, bölünmemiş /24'te .1. Pingler bir anda
    | değil, `spread` saniyeye yayılarak tek tek çıkar. Yanıt veren
    | "kullanılıyor" sayılır. Ping konteynerden çıkar; ağlara oradan
    | ulaşılabilmelidir.
    |
    | timeout: bir pingin yanıt bekleme süresi (saniye).
    */
    'gateway_ping' => [
        'enabled' => (bool) env('GATEWAY_PING_ENABLED', true),
        'binary' => env('GATEWAY_PING_BINARY', 'ping'),
        'timeout' => (int) env('GATEWAY_PING_TIMEOUT', 1),
        /* Tur aralığı (dakika) ve gateway başına ping adedi; panelden de ayarlanır (config/settings.php). */
        'interval_minutes' => (int) env('GATEWAY_PING_INTERVAL_MINUTES', 10),
        'count' => (int) env('GATEWAY_PING_COUNT', 5),
        /* Aynı anda pinglenen gateway sayısı: tur bu boyda paketlere bölünür. */
        'concurrency' => (int) env('GATEWAY_PING_CONCURRENCY', 20),
    ],

];
