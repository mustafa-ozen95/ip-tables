{{--
    Kaydın adı; vurgu rengi seçilmişse kalın ve renkli (bkz. Highlight).

    $record
--}}
@if ($record->highlight && $record->name)
    <strong class="highlight highlight--{{ $record->highlight->value }}">{{ $record->name }}</strong>
@else
    {{ $record->name ?? '—' }}
@endif
