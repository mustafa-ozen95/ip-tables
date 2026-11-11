{{--
    Listede bir bölüm. Alt grup bölümü katlanır ve kapalı gelir; başlıkta
    blok sayısı ve boş pay durur. Seçili kayıt ya da açılacak blok içindeyse
    açık gelir (bkz. IpAddressListing::sections).
--}}
@if ($section['title'] !== null)
    <details class="sub-group" @if ($section['open']) open @endif>
        <summary class="credential-section sub-group__head">
            <span class="block-node__toggle" aria-hidden="true"></span>
            <span @class(['credential-section__name', 'highlight highlight--'.$section['highlight'] => $section['highlight'] ?? null])>{{ $section['title'] }}</span>
            @if ($section['summary'])
                <span class="sub-group__meta">{{ $section['summary'] }}@if ($section['free']) · @include('ip-addresses.partials.free-share', ['share' => $section['free']])@endif</span>
            @endif
        </summary>

        @include('ip-addresses.partials.section-body')
    </details>
@else
    @include('ip-addresses.partials.section-body')
@endif
