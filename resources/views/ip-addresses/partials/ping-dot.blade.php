{{--
    Gateway ping noktası (yeşil/kırmızı, yanıp söner) ve ↻ şimdi kontrol et.
    ↻ yalnız "ping" yetkisi olana çizilir.

    $ping: GatewayPingStatus::of() ya da null (henüz pinglenmedi)
    $record: pinglenen kayıt
--}}
@if ($ping)
    <span class="status ping-dot" title="{{ $ping['label'] }}">
        <span class="dot dot--{{ $ping['variant']->value }}" aria-hidden="true"></span>
        <span class="visually-hidden">{{ $ping['label'] }}</span>
    </span>
@endif
@can('ping', $record)
    <form method="POST" action="{{ route('ip-addresses.ping', $record) }}" class="ping-now">
        @csrf
        <button type="submit" class="btn btn--sm btn--icon btn--ghost" title="Şimdi kontrol et"
                aria-label="{{ $record->notation }} gateway'ini şimdi kontrol et">↻</button>
    </form>
@endcan
