{{--
    Parça tablosunun Ad hücresi: ad ve altında açıklama.

    Düzenleme yetkisi olan, ada ya da açıklamaya çift tıklayarak yerinde
    düzenler (bkz. inline-edit.js). Açıklaması olmayan parçada açıklama
    satırı boştur ve yer kaplamaz; hücrenin boş yerine çift tıklamak onu
    açar.

    $record
--}}
@can('updateDetails', $record)
    <td data-label="Ad" data-inline-cell title="Düzenlemek için çift tıklayın">
        <span @include('ip-addresses.partials.inline-edit-attrs', ['field' => 'name', 'value' => $record->name])>@include('ip-addresses.partials.record-name')</span>
        <div class="text-sm text-muted part-note"
             @include('ip-addresses.partials.inline-edit-attrs', ['field' => 'description', 'value' => $record->description])>{{ $record->description }}</div>
    </td>
@else
    <td data-label="Ad">
        @include('ip-addresses.partials.record-name')
        @if ($record->description)
            <div class="text-sm text-muted part-note">{{ $record->description }}</div>
        @endif
    </td>
@endcan
