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
                @elseif ($roots === [] && $wide === [])
                    <x-ui.empty title="IPv4 adresi yok" text="Bu görünümde planlanacak IPv4 adresi ya da bloğu yok." />
                @else
                    <div class="block-plan">
                        <p class="text-sm text-muted">
                            Bloklar /22'den /32'ye kadar ikiye bölünür. /24 ve daha geniş kayıtlar adres alanını tanımlar;
                            "Boş" alanda atama yoktur, "Kayıtsız" alan hiçbir bloğa ait değildir.
                        </p>

                        @if ($wide !== [])
                            <section class="panel">
                                <div class="credential-section">
                                    <span class="credential-section__name">/22'den geniş bloklar</span>
                                </div>
                                @foreach ($wide as $record)
                                    <div class="block-node__row block-node__row--leaf">
                                        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $record->uuid]) }}"
                                           class="block-node__address mono">{{ $record->notation }}</a>
                                        <span class="block-node__records truncate">{{ $record->name ?? '' }}</span>
                                    </div>
                                @endforeach
                            </section>
                        @endif

                        @foreach ($roots as $node)
                            <section class="panel block-plan__root">
                                @include('ip-addresses.partials.plan-node')
                            </section>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layout.app>
