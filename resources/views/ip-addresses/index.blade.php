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
                        <span class="row-item__meta">{{ $allCount }}</span>
                    </a>
                @endif

                @forelse ($groups as $group)
                    <a href="{{ route('ip-addresses.index', ['grup' => $group->uuid]) }}"
                       class="row-item"
                       @if ($selectedGroup?->is($group)) aria-selected="true" @endif>
                        <span class="row-item__label">{{ $group->name }}</span>
                        <span class="row-item__meta">{{ $group->ip_addresses_count }}</span>
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

                @if ($canCreate)
                    <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'pencere' => 'ekle']) }}"
                       class="btn btn--primary btn--sm" data-modal-open="record-add">Yeni adres</a>
                @endif
            </div>

            <div class="column__body">
                <div class="grid-row__head address-row">
                    <span class="address-row__address">Adres</span>
                    <span class="address-row__name">Ad</span>
                    <span class="address-row__type">Tür</span>
                    <span class="address-row__description">Açıklama</span>
                </div>

                @foreach ($sections as $section)
                    @include('ip-addresses.partials.section')
                @endforeach
            </div>
        </div>

        @if ($selected)
            <aside class="detail">
                <div class="detail__head">
                    <div class="detail__title">{{ $selected['notation'] }}</div>
                    <p class="detail__subtitle">{{ $selected['groupName'] }}</p>
                </div>

                <div class="detail__body">
                    <div class="detail__section">
                        <span class="eyebrow">Adres</span>
                        <div class="detail__block">
                            <x-ui.copyable label="Adres">{{ $selected['notation'] }}</x-ui.copyable>
                        </div>
                        <div class="detail__block">
                            <x-ui.badge :variant="$selected['kind']->badge()">{{ $selected['kind']->label() }}</x-ui.badge>
                        </div>
                        <x-ui.facts class="detail__block" :rows="$selected['facts']" />
                    </div>

                    @if ($selected['description'])
                        <div class="detail__section">
                            <span class="eyebrow">Açıklama</span>
                            <p class="credential-note">{{ $selected['description'] }}</p>
                        </div>
                    @endif

                    @can('viewAny', App\Models\ActivityLog::class)
                        <div class="detail__section">
                            <span class="eyebrow">Geçmiş</span>
                            <a href="{{ route('logs.index', ['kayit' => $selected['uuid']]) }}"
                               class="btn btn--sm detail__block">Kayıtlarda göster</a>
                        </div>
                    @endcan
                </div>

                <div class="detail__foot">
                    @if ($selected['canUpdate'])
                        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $selected['uuid'], 'pencere' => 'duzenle']) }}"
                           class="btn btn--sm" data-modal-open="record-edit">Düzenle</a>
                    @endif

                    @if ($selected['canSplit'])
                        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $selected['uuid'], 'pencere' => 'bol']) }}"
                           class="btn btn--sm" data-modal-open="block-split">Böl</a>
                    @endif

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
            </aside>
        @endif
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

    {{-- Böl: /24'ü parçalara ayırır (bkz. Ipv4SplitLayout). --}}
    @if ($selected && $selected['canSplit'] && ! in_array($window, ['ekle', 'duzenle'], true))
        <x-ui.modal id="block-split" :title="$selected['notation'].' bölünecek'" :open="$window === 'bol'">
            <form method="POST" action="{{ route('ip-addresses.split', $selected['model']) }}" autocomplete="off" data-inline>
                @csrf
                <input type="hidden" name="_pencere" value="bol">

                <div class="modal__body">
                    <x-ui.errors class="modal__alert" />

                    <x-form.field name="layout" label="Parçalar"
                                  hint="Boyları blok başından sırayla yazın; son boy bloğun sonuna kadar tekrarlanır. 29 = 32 tane /29; 28x2 29 = iki /28, kalanı /29; 29x10 28x2 27 … gibi karışık da olur. Bölme tek kattır.">
                        <x-form.input name="layout" mono required maxlength="500" spellcheck="false" />
                    </x-form.field>
                </div>

                <x-ui.modal-actions submit="Böl" />
            </form>
        </x-ui.modal>
    @endif
</x-layout.app>
