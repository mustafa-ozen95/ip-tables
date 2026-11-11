<x-layout.app title="IP adresleri">
    <div class="columns">
        {{-- Gruplar: yalnız görme yetkisi olanlar (IpAddressListing → visibleTo). --}}
        <div class="column">
            <div class="column__head">
                <span class="eyebrow">Gruplar</span>
                <span class="column__count">{{ $groups->count() }}</span>
            </div>

            <div class="column__body">
                @if ($groups->isNotEmpty())
                    <a href="{{ route('ip-addresses.index') }}"
                       class="row-item"
                       @if ($selectedGroup === null) aria-selected="true" @endif>
                        <span class="row-item__label">Hepsi</span>
                        <span class="row-item__meta">@if ($allFree)@include('ip-addresses.partials.free-share', ['share' => $allFree]) · @endif{{ $allCount }}</span>
                    </a>
                @endif

                @forelse ($groups as $group)
                    <a href="{{ route('ip-addresses.index', ['grup' => $group->uuid]) }}"
                       class="row-item"
                       @if ($selectedGroup?->is($group)) aria-selected="true" @endif>
                        <span class="row-item__label">{{ $group->name }}</span>
                        <span class="row-item__meta">@isset($groupFree[$group->uuid])@include('ip-addresses.partials.free-share', ['share' => $groupFree[$group->uuid]]) · @endisset{{ $group->ip_addresses_count }}</span>
                    </a>
                @empty
                    <x-ui.empty title="Grup yok" text="Size henüz bir grup yetkisi verilmemiş." />
                @endforelse
            </div>
        </div>

        <div class="column column--fill">
            <div class="column__head">
                <span class="eyebrow">{{ $selectedGroup?->name ?? ($query ? 'Arama sonucu' : 'Hepsi') }}</span>
                <form method="GET" class="row row--wrap filter-bar">
                    @if ($selectedGroup)
                        <input type="hidden" name="grup" value="{{ $selectedGroup->uuid }}">
                    @endif
                    <x-ui.search name="q" :value="$query" :standalone="false"
                                 placeholder="Adres, ad ya da açıklama" label="Adres filtrele" />
                    <button type="submit" class="btn btn--sm">Filtrele</button>
                </form>

                <span class="spacer"></span>

                @can('exportAny', App\Models\IpAddress::class)
                    <form method="POST" action="{{ route('ip-addresses.export') }}">
                        @csrf
                        @if ($selectedGroup)
                            <input type="hidden" name="grup" value="{{ $selectedGroup->uuid }}">
                        @endif
                        @if ($query !== '')
                            <input type="hidden" name="q" value="{{ $query }}">
                        @endif
                        <button type="submit" class="btn btn--sm">Excel</button>
                    </form>
                @endcan

                @if ($canCreate)
                    <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'pencere' => 'ekle']) }}"
                       class="btn btn--primary btn--sm" data-modal-open="record-add">Yeni adres</a>
                @endif
            </div>

            <div class="column__body">
                <div class="grid-row__head address-row">
                    <span class="address-row__address">Adres</span>
                    <span class="address-row__name">Ad</span>
                    <span class="address-row__type">
                        Durum
                        {{-- Sıralama yerinde çizilir: açık bölüm/blok ve kaydırma korunur (bkz. navigation.js). --}}
                        <a href="{{ $sort['url'] }}" @class(['sort-toggle', 'sort-toggle--active' => $sort['active']]) data-in-place
                           title="{{ $sort['label'] }}" aria-label="{{ $sort['label'] }}">{{ $sort['icon'] }}</a>
                    </span>
                    <span class="address-row__description">Açıklama</span>
                    <span class="address-row__actions"></span>
                </div>

                @foreach ($sections as $section)
                    @include('ip-addresses.partials.section')
                @endforeach
            </div>
        </div>
    </div>

    {{-- Aynı anda tek pencere: biri doğrulama hatasıyla açıksa diğeri çizilmez. --}}
    @if ($canCreate && ! in_array($window, ['duzenle', 'bol'], true))
        <x-ip-addresses.modal id="record-add"
                              window="ekle"
                              title="Yeni adres"
                              :action="route('ip-addresses.store')"
                              :open="$window === 'ekle'"
                              :selected-group="$selectedGroup?->uuid"
                              :group-options="$groupOptions"
                              :sub-group-options="$subGroupOptions"
                              :sub-group-map="$subGroupMap" />
    @endif

    @if ($selected && $selected['canUpdate'] && ! in_array($window, ['ekle', 'bol'], true))
        <x-ip-addresses.modal id="record-edit"
                              window="duzenle"
                              :title="$selected['notation']"
                              :action="route('ip-addresses.update', $selected['model'])"
                              method="PUT"
                              submit="Güncelle"
                              :open="$window === 'duzenle'"
                              :record="$selected['model']"
                              :group-options="$updateGroupOptions"
                              :sub-group-options="$subGroupOptions"
                              :sub-group-map="$subGroupMap" />
    @endif

    {{--
        ⋮ penceresi: kaydın bilgisi ve satırda yer almayan işlemler. Düğmeler
        yalnız isteğin de geçireceği durumda çizilir (IpAddressListing::selected).
    --}}
    @if ($selected && $window === 'islemler')
        <x-ui.modal id="record-actions" :title="$selected['notation']" :open="true">
            <div class="modal__body stack">
                <p class="field__hint">{{ $selected['groupName'] }}</p>
                <x-ui.copyable label="Adres">{{ $selected['notation'] }}</x-ui.copyable>
                <x-ui.facts :rows="$selected['facts']" />

                @if ($selected['description'])
                    <p class="credential-note">{{ $selected['description'] }}</p>
                @endif

                <div class="row row--wrap">
                    @if ($selected['canUpdate'])
                        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $selected['uuid'], 'pencere' => 'duzenle']) }}"
                           class="btn btn--sm">Düzenle</a>
                    @endif

                    @if ($selected['canSplit'])
                        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $selected['uuid'], 'pencere' => 'bol']) }}"
                           class="btn btn--sm">Böl</a>
                    @endif

                    @if ($selected['canMerge'])
                        <form method="POST" action="{{ route('ip-addresses.merge', $selected['model']) }}" data-inline
                              data-confirm="{{ $selected['mergeLabel'] }}. Adı olan parça kalır, öbürü silinir."
                              data-confirm-title="Parçaları birleştir"
                              data-confirm-reasonless>
                            @csrf
                            <button type="submit" class="btn btn--sm">Birleştir</button>
                        </form>
                    @endif

                    @if ($selected['canSubdivide'])
                        <form method="POST" action="{{ route('ip-addresses.subdivide', $selected['model']) }}" class="row" data-inline
                              data-confirm="{{ $selected['notation'] }} seçilen boyda parçalara bölünecek. Bu kayıt ilk parça olur ve adını korur; ötekiler boş açılır."
                              data-confirm-title="Parçala"
                              data-confirm-reasonless>
                            @csrf
                            <x-form.select name="prefix" id="subdivide-prefix" class="select--cell" aria-label="Parça boyu"
                                           :options="$selected['subdividePrefixes']" />
                            <button type="submit" class="btn btn--sm">Parçala</button>
                        </form>
                    @endif

                    @can('viewAny', App\Models\ActivityLog::class)
                        <a href="{{ route('logs.index', ['kayit' => $selected['uuid']]) }}" class="btn btn--sm">Kayıtlarda göster</a>
                    @endcan

                    @if ($selected['canUnsplit'])
                        <x-ui.delete-form :action="route('ip-addresses.unsplit', $selected['model'])"
                                          confirm="{{ $selected['notation'] }} bölmesi kaldırılacak; {{ $selected['partCount'] }} parça adlarıyla birlikte silinecek."
                                          title="Bölmeyi kaldır"
                                          label="Bölmeyi kaldır"
                                          verify="{{ $selected['notation'] }}" />
                    @endif

                    @if ($selected['canDelete'])
                        <x-ui.delete-form :action="route('ip-addresses.destroy', $selected['model'])"
                                          confirm="{{ $selected['notation'] }} silinecek."
                                          title="Adresi sil"
                                          verify="{{ $selected['notation'] }}" />
                    @endif
                </div>
            </div>

            <x-ui.modal-actions :submit="false" cancel="Kapat" />
        </x-ui.modal>
    @endif

    {{--
        Böl: /24'ün boş alanını aralık aralık böler (bkz. Ipv4SplitLayout).
        Form YERİNDE (data-inline) gönderilmez: yerinde gönderim basılan
        düğmeyi taşımıyor, "Önizle" ile "Böl" ayrılamazdı.
    --}}
    @if ($selected && $selected['canSplit'] && ! in_array($window, ['ekle', 'duzenle'], true))
        <x-ui.modal id="block-split" :title="$selected['notation'].' bölünecek'" :open="$window === 'bol'">
            <form method="POST" action="{{ route('ip-addresses.split', $selected['model']) }}" autocomplete="off" data-conditional-form>
                @csrf
                <input type="hidden" name="_pencere" value="bol">

                <div class="modal__body stack">
                    <x-ui.errors class="modal__alert" />

                    @if ($selected['split']['preview'])
                        <div class="alert alert--info">Önizleme: {{ $selected['split']['preview'] }}</div>
                    @endif

                    <p class="field__hint">Boş alan: {{ $selected['split']['free'] }}</p>

                    <x-form.field name="ranges.0.prefix" id="split-0-prefix" label="Subnet">
                        <x-form.select name="ranges[0][prefix]" id="split-0-prefix"
                                       :options="$selected['split']['prefixes']" :selected="$selected['split']['row']['prefix']" />
                    </x-form.field>

                    <x-form.field name="ranges.0.count" id="split-0-count" label="Adet">
                        <x-form.select name="ranges[0][count]" id="split-0-count" size="6"
                                       :options="$selected['split']['counts']" :selected="$selected['split']['row']['count']"
                                       data-linked="ranges[0][prefix]"
                                       data-linked-options="{{ json_encode($selected['split']['countMap']) }}" />
                    </x-form.field>

                    <x-form.field name="ranges.0.slot" id="split-0-slot" label="Müsait aralık">
                        <x-form.select name="ranges[0][slot]" id="split-0-slot" size="6"
                                       :options="$selected['split']['slots']" :selected="$selected['split']['row']['slot']"
                                       data-linked="ranges[0][count]"
                                       data-linked-options="{{ json_encode($selected['split']['slotMap']) }}" />
                    </x-form.field>

                    <p class="field__hint">
                        Sırayla subnet, kaç parça, sonra aralık: aralık listesi o kadar parçanın art arda sığdığı boş yerleri
                        gösterir (/29, 10 parça → .0 – .79). Kalan boş alan sonra yine bölünür; başka boy için Böl'ü yeniden
                        açın. /31 ve /32 için parçayı ⋮ → "Parçala".
                    </p>
                </div>

                <x-ui.modal-actions submit="Böl">
                    <button type="submit" name="onizle" value="1" class="btn">Önizle</button>
                </x-ui.modal-actions>
            </form>
        </x-ui.modal>
    @endif
</x-layout.app>
