{{--
    Listede bir /24 blok (bkz. Ipv4BlockPlan). Bölünmüş bloğun satırına
    tıklamak parçalarını açar; blok kapalı gelir (bkz. panel-custom.css,
    BLOK LİSTESİ). İşlemler satırın sonundadır. Kısmen bölünmüş blokta
    bölünmemiş alan da tabloda satırdır; Böl'ü bloğa gider.
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
                                <td class="table__cell--mono" data-label="Ağ geçidi">{{ $part['gateway'] ?? '—' }}</td>
                                <td class="table__cell--mono" data-label="İlk adres">{{ $part['first'] }}</td>
                                <td class="table__cell--mono" data-label="Son adres">{{ $part['last'] }}</td>
                                <td class="table__cell--mono" data-label="Maske">{{ $part['mask'] ?? '—' }}</td>
                                <td data-label="Ad">{{ $part['free'] ? $part['name'] : ($part['record']->name ?? '—') }}</td>
                                <td data-label="Durum"><x-ui.badge :variant="$part['badge']">{{ $part['stateLabel'] }}</x-ui.badge></td>
                                <td class="table__cell--tight">
                                    @if ($part['free'])
                                        @if ($block['canSplit'])
                                            <div class="table__row-actions">
                                                <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $block['record']->uuid, 'pencere' => 'bol']) }}"
                                                   class="btn btn--sm">Böl</a>
                                            </div>
                                        @endif
                                    @else
                                        @include('ip-addresses.partials.row-actions', ['record' => $part['record'], 'canEdit' => $rowCan[$part['record']->address_group_id]['update'] ?? false, 'canSplit' => false])
                                    @endif
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
