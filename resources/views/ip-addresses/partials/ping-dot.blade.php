{{--
    Gateway ping noktası (bkz. GatewayPingStatus): yeşil yanıt veriyor,
    kırmızı vermiyor; yanıp söner (panel-custom.css, PING NOKTASI). Ayrıntı
    üzerine gelince ve ekran okuyucuda yazar.

    $ping: array{up, variant, label}
--}}
<span class="status ping-dot" title="{{ $ping['label'] }}">
    <span class="dot dot--{{ $ping['variant']->value }}" aria-hidden="true"></span>
    <span class="visually-hidden">{{ $ping['label'] }}</span>
</span>
