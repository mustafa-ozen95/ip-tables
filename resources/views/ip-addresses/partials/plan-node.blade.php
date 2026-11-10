{{--
    Blok ağacının bir düğümü (bkz. Ipv4BlockPlan). Çocuğu olan düğüm
    katlanır (<details>, betiksiz çalışır); boş yaprak tek satırdır.
--}}
@if ($node['children'] !== [])
    <details class="block-node" open>
        <summary class="block-node__row">
            @include('ip-addresses.partials.plan-row')
        </summary>

        <div class="block-node__children">
            @foreach ($node['children'] as $child)
                @include('ip-addresses.partials.plan-node', ['node' => $child])
            @endforeach
        </div>
    </details>
@else
    <div class="block-node__row block-node__row--leaf">
        @include('ip-addresses.partials.plan-row')
    </div>
@endif
