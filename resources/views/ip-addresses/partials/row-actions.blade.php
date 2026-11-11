{{--
    Satırın sonundaki ⋮: kaydın bilgisini ve işlemlerini (düzenle, böl,
    birleştir, parçala, bölmeyi kaldır, sil, kayıtlar) pencerede açar.
    Hangi işlemin çıkacağına pencere karar verir (IpAddressListing::selected).

    $record
--}}
<a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $record->uuid, 'pencere' => 'islemler']) }}"
   class="btn btn--sm btn--icon" aria-label="{{ $record->notation }} işlemleri" title="İşlemler">⋮</a>
