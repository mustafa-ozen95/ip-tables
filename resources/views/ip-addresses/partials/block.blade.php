{{--
    Listede bir /24 blok (bkz. Ipv4BlockPlan). Bölünmüş bloğun satırına
    tıklamak parçalarını açar; blok kapalı gelir (bkz. panel-custom.css,
    BLOK LİSTESİ). İşlemler satırın sonundaki ⋮ penceresindedir. Kısmen
    bölünmüş blokta bölünmemiş alan da tabloda satırdır; bloğun ⋮'ündeki
    Böl onu böler.
--}}
@if ($block['parts'] !== [])
    <details class="block-node" @if ($block['open']) open @endif>
        <summary class="grid-row address-row"
                 @if ($selected && $selected['uuid'] === $block['record']->uuid) aria-selected="true" @endif>
            @include('ip-addresses.partials.block-row')
        </summary>

        <div class="block-node__parts">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ağ</th>
                            <th>Ağ geçidi</th>
                            <th>İlk adres</th>
                            <th>Son adres</th>
                            <th>Maske</th>
                            <th>Ad</th>
                            <th>Durum</th>
                            <th><span class="visually-hidden">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['parts'] as $part)
                            <tr>
                                <td class="table__cell--mono" data-label="Ağ">{{ $part['notation'] }}</td>
                                <td class="table__cell--mono" data-label="Ağ geçidi">
                                    {{ $part['gateway'] ?? '—' }}
                                    @if ($part['ping'])
                                        @include('ip-addresses.partials.ping-dot', ['ping' => $part['ping']])
                                    @endif
                                </td>
                                <td class="table__cell--mono" data-label="İlk adres">{{ $part['first'] }}</td>
                                <td class="table__cell--mono" data-label="Son adres">{{ $part['last'] }}</td>
                                <td class="table__cell--mono" data-label="Maske">{{ $part['mask'] ?? '—' }}</td>
                                <td data-label="Ad">{{ $part['free'] ? $part['name'] : ($part['record']->name ?? '—') }}</td>
                                <td data-label="Durum"><x-ui.badge :variant="$part['badge']">{{ $part['stateLabel'] }}</x-ui.badge></td>
                                <td class="table__cell--tight table__cell--right">
                                    @unless ($part['free'])
                                        @include('ip-addresses.partials.row-actions', ['record' => $part['record']])
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </details>
@else
    <div class="grid-row address-row"
         @if ($selected && $selected['uuid'] === $block['record']->uuid) aria-selected="true" @endif>
        @include('ip-addresses.partials.block-row')
    </div>
@endif
