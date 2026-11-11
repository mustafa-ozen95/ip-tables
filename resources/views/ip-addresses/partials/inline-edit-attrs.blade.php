{{--
    Çift tıklayarak düzenlenen alanın öznitelikleri (bkz. inline-edit.js).
    Yetki çağıranda denetlenir; sunucu da yeniden denetler.

    $record, $field (name|description), $value
--}}
data-inline-edit="{{ $field }}"
data-inline-url="{{ route('ip-addresses.field', $record) }}"
data-inline-value="{{ $value }}"
data-inline-label="{{ $record->notation }} {{ $field === 'description' ? 'açıklaması' : 'adı' }}"
data-inline-max="{{ App\Http\Requests\IpAddress\StoreIpAddressRequest::MAX_LENGTH[$field] }}"
