{{-- Bloğun satırı: Tür sütununda bloğun doluluğu, açıklamada parça sayısı. --}}
<span class="address-row__address block-node__address">
    <span @class(['block-node__toggle', 'block-node__toggle--none' => $block['parts'] === []]) aria-hidden="true"></span>
    <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $block['record']->uuid]) }}"
       class="credential-row__link mono truncate">{{ $block['notation'] }}</a>
</span>

<span class="address-row__name truncate">
    {{ $block['record']->name ?? '—' }}
    @if ($selectedGroup === null)
        <span class="text-sm text-muted">· {{ $block['record']->location }}</span>
    @endif
</span>

<span class="address-row__type">
    <x-ui.badge :variant="$block['badge']">{{ $block['stateLabel'] }}</x-ui.badge>
</span>

<span class="address-row__description text-sm text-muted truncate">{{ $block['partsLabel'] }}@if ($block['record']->description) · {{ $block['record']->description }}@endif</span>
