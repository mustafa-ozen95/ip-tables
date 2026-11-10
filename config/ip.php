<?php

declare(strict_types=1);

return [

    /*
    | Yerel sayılan aralıklar. Adresin türü buradan belirlenir:
    | bu aralıklardan birindeyse "Yerel IPv4/IPv6", değilse "IPv4/IPv6"
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

];
