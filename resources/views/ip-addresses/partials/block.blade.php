{{--
    Listede bir /24 blok (bkz. Ipv4BlockPlan). Bölünmüş bloğun satırına
    tıklamak parçalarını açar; blok kapalı gelir (bkz. panel-custom.css,
    BLOK LİSTESİ). İşlemler satırın sonundaki ⋮ penceresindedir. Kısmen
    bölünmüş blokta bölünmemiş alan da tabloda satırdır; bloğun ⋮'ündeki
    Böl onu böler.

    Ad dışındaki sütunlar içeriği kadardır (table__cell--tight); kalan yer
    Ad'ındır; parçanın açıklaması adın altında tam yazılır. Ping noktası gateway'in yanında AYRI hücrededir: adres boyu
    değişse de noktalar alt alta hizalanır.
--}}
@if ($block['parts'] !== [])
    <details data-open-key="{{ $block['record']->uuid }}" class="block-node" @if ($block['open']) open @endif>
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
                            <th colspan="2">Ağ geçidi</th>
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
                                <td class="table__cell--mono table__cell--tight" data-label="Ağ">{{ $part['notation'] }}</td>
                                <td class="table__cell--mono table__cell--tight" data-label="Ağ geçidi">{{ $part['gateway'] ?? '—' }}</td>
                                <td class="table__cell--tight ping-cell" data-label="Ping">
                                    @if ($part['pingable'])
                                        @include('ip-addresses.partials.ping-dot', ['ping' => $part['ping'], 'record' => $part['record']])
                                    @endif
                                </td>
                                <td class="table__cell--mono table__cell--tight" data-label="İlk adres">{{ $part['first'] }}</td>
                                <td class="table__cell--mono table__cell--tight" data-label="Son adres">{{ $part['last'] }}</td>
                                <td class="table__cell--mono table__cell--tight" data-label="Maske">{{ $part['mask'] ?? '—' }}</td>
                                @if ($part['free'])
                                    <td data-label="Ad">{{ $part['name'] }}</td>
                                @else
                                    @include('ip-addresses.partials.part-name', ['record' => $part['record']])
                                @endif
                                <td class="table__cell--tight" data-label="Durum"><x-ui.badge :variant="$part['badge']">{{ $part['stateLabel'] }}</x-ui.badge></td>
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
