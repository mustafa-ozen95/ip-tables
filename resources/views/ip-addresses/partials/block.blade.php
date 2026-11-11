{{--
    Listede bir /24 blok (bkz. Ipv4BlockPlan). Bölünmüş bloğun satırına
    tıklamak parçalarını açar; blok kapalı gelir (bkz. panel-custom.css,
    BLOK LİSTESİ). İşlemler satırın sonundaki ⋮ penceresindedir. Kısmen
    bölünmüş blokta bölünmemiş alan da tabloda satırdır; bloğun ⋮'ündeki
    Böl onu böler.

    Ad dışındaki sütunlar içeriği kadardır (table__cell--tight); kalan yer
    Ad'ındır; parçanın açıklaması adın altında tam yazılır. Ping noktası gateway'in yanında AYRI hücrededir: adres boyu
    değişse de noktalar alt alta hizalanır.
--}}
@if ($block['parts'] !== [])
    <details data-open-key="{{ $block['record']->uuid }}" class="block-node" @if ($block['open']) open @endif>
        <summary class="grid-row address-row"
                 @if ($selected && $selected['uuid'] === $block['record']->uuid) aria-selected="true" @endif>
            @include('ip-addresses.partials.block-row')
        </summary>

        <div class="block-node__parts">
            @include('ip-addresses.partials.parts-table', ['parts' => $block['parts']])
        </div>
    </details>
@else
    <div class="grid-row address-row"
         @if ($selected && $selected['uuid'] === $block['record']->uuid) aria-selected="true" @endif>
        @include('ip-addresses.partials.block-row')
    </div>
@endif
