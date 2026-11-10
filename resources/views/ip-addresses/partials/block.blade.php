{{--
    Listede bir /24 blok (bkz. Ipv4BlockPlan). Satırın tamamı bağlantıdır,
    ayrıntıyı sağda açar. Bölünmüş bloğun parçalarını YALNIZ soldaki ok
    açar; blok kapalı gelir (bkz. panel-custom.css, BLOK LİSTESİ).
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
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['parts'] as $part)
                            <tr>
                                <td class="table__cell--mono" data-label="Ağ">
                                    <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $part['record']->uuid]) }}">{{ $part['notation'] }}</a>
                                </td>
                                <td class="table__cell--mono" data-label="Ağ geçidi">{{ $part['gateway'] ?? '—' }}</td>
                                <td class="table__cell--mono" data-label="İlk adres">{{ $part['first'] }}</td>
                                <td class="table__cell--mono" data-label="Son adres">{{ $part['last'] }}</td>
                                <td class="table__cell--mono" data-label="Maske">{{ $part['mask'] }}</td>
                                <td data-label="Ad">{{ $part['record']->name ?? '—' }}</td>
                                <td data-label="Durum"><x-ui.badge :variant="$part['badge']">{{ $part['stateLabel'] }}</x-ui.badge></td>
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
