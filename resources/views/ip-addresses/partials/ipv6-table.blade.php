{{--
    Bölümün IPv6 tablosu (bkz. IpAddressListing::tabled). Alt grubu (/48)
    olan tablo IPv4'teki /24 bloğu gibi katlanır başlığın altındadır;
    bölüm zaten o alt grupsa başlık yoktur.
--}}
@foreach ($section['table'] as $table)
    @if ($table['heading'])
        <details data-open-key="{{ $table['heading']['key'] }}" class="block-node" @if ($table['heading']['open']) open @endif>
            <summary class="grid-row address-row">
                <span class="address-row__address block-node__address">
                    <span class="block-node__toggle" aria-hidden="true"></span>
                    <span class="mono truncate">{{ $table['heading']['notation'] }}</span>
                </span>
                <span class="address-row__name truncate">
                    @if ($selectedGroup === null)
                        <span class="text-sm text-muted">{{ $table['heading']['location'] }}</span>
                    @endif
                </span>
                <span class="address-row__type">
                    <x-ui.badge :variant="$table['heading']['badge']">{{ $table['heading']['stateLabel'] }}</x-ui.badge>
                </span>
                <span class="address-row__description text-sm text-muted">{{ $table['heading']['note'] }}</span>
                <span class="address-row__actions"></span>
            </summary>

            <div class="block-node__parts">
                @include('ip-addresses.partials.parts-table', ['parts' => $table['parts']])
            </div>
        </details>
    @else
        <div class="block-node__parts">
            @include('ip-addresses.partials.parts-table', ['parts' => $table['parts']])
        </div>
    @endif
@endforeach
