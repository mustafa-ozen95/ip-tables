{{--
    Blok planında bir /24 (bkz. Ipv4BlockPlan). Bölünmüş blok katlanır ve
    KAPALI gelir; yalnız soldaki ok açar (bkz. panel-custom.css, BLOK PLANI).
    Bölünmemiş blok tek satırdır, yanında "Böl" durur.
--}}
@if ($block['parts'] !== [])
    <details class="block-node" @if ($block['open']) open @endif>
        <summary class="block-node__row">
            <span class="block-node__toggle" aria-hidden="true"></span>
            @include('ip-addresses.partials.plan-row')
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

            @if ($block['canUnsplit'])
                <div class="block-node__foot">
                    <x-ui.delete-form :action="route('ip-addresses.unsplit', $block['record'])"
                                      confirm="{{ $block['notation'] }} bölmesi kaldırılacak; {{ count($block['parts']) }} parça adlarıyla birlikte silinecek."
                                      title="Bölmeyi kaldır"
                                      label="Bölmeyi kaldır"
                                      verify="{{ $block['notation'] }}" />
                </div>
            @endif
        </div>
    </details>
@else
    <div class="block-node__row">
        <span class="block-node__toggle block-node__toggle--none" aria-hidden="true"></span>
        @include('ip-addresses.partials.plan-row')

        @if ($block['canSplit'])
            <a href="{{ route('ip-addresses.plan', ['grup' => $selectedGroup?->uuid, 'pencere' => 'bol', 'blok' => $block['record']->uuid]) }}"
               class="btn btn--sm">Böl</a>
        @endif
    </div>
@endif
