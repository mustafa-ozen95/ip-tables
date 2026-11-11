{{-- Bloğun satırı: Tür sütununda bloğun doluluğu, açıklamada parça sayısı ve
     kaydın açıklaması; açıklama kırpılmaz, satır uzar. --}}
<span class="address-row__address block-node__address">
    <span @class(['block-node__toggle', 'block-node__toggle--none' => $block['parts'] === []]) aria-hidden="true"></span>
    <span class="mono truncate">{{ $block['notation'] }}</span>
    @if ($block['pingable'])
        @include('ip-addresses.partials.ping-dot', ['ping' => $block['ping'], 'record' => $block['record']])
    @endif
</span>

<span class="address-row__name truncate">
    @include('ip-addresses.partials.record-name', ['record' => $block['record']])
    @if ($selectedGroup === null)
        <span class="text-sm text-muted">· {{ $block['record']->location }}</span>
    @endif
</span>

<span class="address-row__type">
    <x-ui.badge :variant="$block['badge']">{{ $block['stateLabel'] }}</x-ui.badge>
</span>

<span class="address-row__description text-sm text-muted">{{ $block['note'] }}</span>

<span class="address-row__actions">
    @include('ip-addresses.partials.row-actions', ['record' => $block['record']])
</span>
