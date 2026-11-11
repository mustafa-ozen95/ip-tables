{{--
    Satırın sonundaki işlemler: Düzenle, Böl (boş alanı kalan blokta) ve ⋮.
    ⋮ kaydın bilgisini ve kalan işlemleri (birleştir, ikiye ayır, bölmeyi
    kaldır, sil, kayıtlar) pencerede açar. Yetkiler gruba göre gelir
    (IpAddressListing::rowAbilities); istek yine kendisi sınar.

    $record, $canEdit, $canSplit
--}}
<div class="table__row-actions">
    @if ($canEdit)
        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $record->uuid, 'pencere' => 'duzenle']) }}"
           class="btn btn--sm">Düzenle</a>
    @endif

    @if ($canSplit)
        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'kayit' => $record->uuid, 'pencere' => 'bol']) }}"
           class="btn btn--sm">Böl</a>
    @endif

    <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $record->uuid, 'pencere' => 'islemler']) }}"
       class="btn btn--sm btn--icon" aria-label="{{ $record->notation }} işlemleri" title="Diğer işlemler">⋮</a>
</div>
