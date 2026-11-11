{{--
    Açıklama; yoksa eğik "Açıklama yok". Yalnız gösterimdir: yerinde
    düzenleme eski değeri data-inline-value'dan alır, bu yazıdan değil.

    $text
--}}
@if (filled($text)){{ $text }}@else<span class="description-empty">Açıklama yok</span>@endif
