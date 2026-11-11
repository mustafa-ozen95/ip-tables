{{--
    Gateway ping noktası (yeşil/kırmızı, yanıp söner) ve ↻ şimdi kontrol et.
    ↻ yalnız "ping" yetkisi olana çizilir. Henüz pinglenmemiş kaydın noktası
    gizli durur: ↻'nin sonucu gelince betik onu açar (bkz. gateway-ping.js).

    $ping: GatewayPingStatus::of() ya da null (henüz pinglenmedi)
    $record: pinglenen kayıt
--}}
<span class="ping" data-ping>
    <span class="status ping-dot" title="{{ $ping['label'] ?? '' }}" data-ping-dot @if (! $ping) hidden @endif>
        <span class="dot dot--{{ ($ping['variant'] ?? App\Enums\BadgeVariant::Gray)->value }}" aria-hidden="true"></span>
        <span class="visually-hidden" data-ping-label>{{ $ping['label'] ?? '' }}</span>
    </span>
    @can('ping', $record)
        <form method="POST" action="{{ route('ip-addresses.ping', $record) }}" class="ping-now"
              data-ping-now data-ping-status="{{ route('ip-addresses.ping.show', $record) }}">
            @csrf
            <button type="submit" class="btn btn--sm btn--icon btn--ghost" title="Şimdi kontrol et"
                    aria-label="{{ $record->notation }} gateway'ini şimdi kontrol et"><span class="ping-now__icon" aria-hidden="true">↻</span></button>
        </form>
    @endcan
</span>
