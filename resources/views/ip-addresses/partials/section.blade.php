{{--
    Listede bir bölüm. Alt grup bölümü katlanır ve kapalı gelir; başlıkta
    blok sayısı ve boş pay durur. Seçili kayıt ya da açılacak blok içindeyse
    ya da açık istenmişse açık gelir (bkz. IpAddressListing::sections).

    Kapalı bölümün içi çizilmez; açılınca yüklenir (bkz. lazy-section.js).
    Betik yoksa bağlantı bölümü açık çizen sayfaya gider.
--}}
@if ($section['title'] !== null)
    <details data-open-key="bolum:{{ $section['key'] }}" class="sub-group" @if ($section['open']) open @endif>
        <summary class="credential-section sub-group__head">
            <span class="block-node__toggle" aria-hidden="true"></span>
            <span @class(['credential-section__name', 'highlight highlight--'.$section['highlight'] => $section['highlight'] ?? null])>{{ $section['title'] }}</span>
            @if ($section['summary'])
                <span class="sub-group__meta">{{ $section['summary'] }}@if ($section['free']) · @include('ip-addresses.partials.free-share', ['share' => $section['free']])@endif</span>
            @endif
        </summary>

        @if ($section['open'])
            @include('ip-addresses.partials.section-body')
        @else
            <div class="block-node__parts text-sm text-muted" data-lazy-section>
                <a href="{{ $section['url'] }}">Bölümü göster</a>
            </div>
        @endif
    </details>
@else
    @include('ip-addresses.partials.section-body')
@endif
