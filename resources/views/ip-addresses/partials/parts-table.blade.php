{{--
    Parça tablosu: ağ, ağ geçidi (ve ping noktası), ilk ve son adres, maske,
    ad ve altında açıklama, durum, ⋮. Bloğun parçaları (bkz. block) ve IPv6
    kayıtları (bkz. section-body) aynı tabloyla çizilir.

    $parts: Ipv4BlockPlan::part() / freeRow() satırları
--}}
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
            @foreach ($parts as $part)
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
