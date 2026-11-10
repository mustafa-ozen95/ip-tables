<span class="block-node__address mono">{{ $node['notation'] }}</span>

<span class="block-node__records truncate">
    @foreach ($node['records'] as $record)
        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $record->uuid]) }}">{{ $record->name ?? $record->notation }}</a>@if ($selectedGroup === null)<span class="text-sm text-muted"> · {{ $record->group->name }}</span>@endif{{ $loop->last ? '' : ', ' }}
    @endforeach
</span>

<span class="block-node__size text-sm text-muted">{{ $node['sizeLabel'] }}</span>
<x-ui.badge :variant="$node['badge']">{{ $node['stateLabel'] }}</x-ui.badge>
