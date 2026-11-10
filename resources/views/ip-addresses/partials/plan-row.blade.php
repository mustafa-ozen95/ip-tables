<span class="block-node__address mono">{{ $block['notation'] }}</span>

<span class="block-node__records truncate">
    <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $block['record']->uuid]) }}">{{ $block['record']->name ?: 'Adsız blok' }}</a>@if ($selectedGroup === null)<span class="text-sm text-muted"> · {{ $block['record']->group->name }}</span>@endif
</span>

<span class="block-node__size text-sm text-muted">{{ $block['partsLabel'] }}</span>
<x-ui.badge :variant="$block['badge']">{{ $block['stateLabel'] }}</x-ui.badge>
