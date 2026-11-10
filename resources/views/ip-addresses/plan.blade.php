<x-layout.app title="Blok planı">
    <div class="columns">
        {{-- Gruplar: yalnız görme yetkisi olanlar (AddressPlanListing → visibleTo). --}}
        <div class="column">
            <div class="column__head">
                <span class="eyebrow">Gruplar</span>
                <span class="column__count">{{ $groups->count() }}</span>
            </div>

            <div class="column__body">
                @if ($groups->isNotEmpty())
                    <a href="{{ route('ip-addresses.plan') }}"
                       class="row-item"
                       @if ($selectedGroup === null) aria-selected="true" @endif>
                        <span class="row-item__label">Hepsi</span>
                        <span class="row-item__meta">{{ $allCount }}</span>
                    </a>
                @endif

                @forelse ($groups as $group)
                    <a href="{{ route('ip-addresses.plan', ['grup' => $group->uuid]) }}"
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
                <span class="eyebrow">Blok planı · {{ $selectedGroup?->name ?? 'Hepsi' }}</span>
                <span class="spacer"></span>
                <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid]) }}" class="btn btn--sm">Liste</a>
            </div>

            <div class="column__body">
                @if ($ipv6Only)
                    <x-ui.empty title="IPv6 grubu" text="Blok planı IPv4 adresleri içindir." />
                @elseif ($blocks === [] && $others === [])
                    <x-ui.empty title="IPv4 bloğu yok" text="Bu görünümde /24 blok yok. Bloğu listeden ekleyin, buradan bölün." />
                @else
                    <div class="block-plan">
                        <p class="text-sm text-muted">
                            Her /24 blok tek kat bölünür; parçalar eşit ya da farklı boyda olabilir. Adı verilen parça
                            "Dolu" sayılır. Bloğu açmak için soldaki oka tıklayın.
                        </p>

                        @if ($blocks !== [])
                            <section class="panel">
                                @foreach ($blocks as $block)
                                    @include('ip-addresses.partials.plan-block')
                                @endforeach
                            </section>
                        @endif

                        @if ($others !== [])
                            <section class="panel">
                                <div class="credential-section">
                                    <span class="credential-section__name">Bloğa bağlı olmayan kayıtlar</span>
                                </div>
                                @foreach ($others as $record)
                                    <div class="block-node__row">
                                        <span class="block-node__toggle block-node__toggle--none" aria-hidden="true"></span>
                                        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $record->uuid]) }}"
                                           class="block-node__address mono">{{ $record->notation }}</a>
                                        <span class="block-node__records truncate">{{ $record->name ?? '' }}</span>
                                    </div>
                                @endforeach
                            </section>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Böl penceresi: ?pencere=bol&blok=… ile sunucudan açık gelir; hata sonrası da aynı adrese döner. --}}
    @if ($splitBlock)
        <x-ui.modal id="block-split" :title="$splitBlock->notation.' bölünecek'" :open="true">
            <form method="POST" action="{{ route('ip-addresses.split', $splitBlock) }}" autocomplete="off" data-inline>
                @csrf

                <div class="modal__body">
                    <x-ui.errors class="modal__alert" />

                    <x-form.field name="layout" label="Parçalar"
                                  hint="Boyları blok başından sırayla yazın; 29x10 on tane /29 demektir. Ör. 29x10 28x2 29x6 27 28x2 29x2 28. Toplam 256 adres olmalı. Bölme tek kattır, parça yeniden bölünmez.">
                        <x-form.input name="layout" mono required maxlength="500" spellcheck="false" />
                    </x-form.field>
                </div>

                <x-ui.modal-actions submit="Böl" />
            </form>
        </x-ui.modal>
    @endif
</x-layout.app>
