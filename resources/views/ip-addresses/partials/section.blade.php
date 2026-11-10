@if ($section['title'] !== null)
    <div class="credential-section">
        <span class="credential-section__name">{{ $section['title'] }}</span>
    </div>
@endif

@forelse ($section['rows'] as $row)
    <div class="grid-row address-row"
         @if ($selected && $selected['uuid'] === $row->uuid) aria-selected="true" @endif>
        {{-- Satırın tamamı tıklanır (credential-row__link katmanı); etiket adres hücresidir. --}}
        <a href="{{ route('ip-addresses.index', ['grup' => $selectedGroup?->uuid, 'q' => $query ?: null, 'kayit' => $row->uuid]) }}"
           class="address-row__address credential-row__link mono truncate">{{ $row->notation }}</a>

        <span class="address-row__name truncate">
            {{ $row->name ?? '—' }}
            @if ($selectedGroup === null)
                <span class="text-sm text-muted">· {{ $row->location }}</span>
            @endif
        </span>

        <span class="address-row__type">
            <x-ui.badge :variant="$row->group->kind->badge()">{{ $row->group->kind->label() }}</x-ui.badge>
        </span>

        <span class="address-row__description text-sm text-muted truncate">{{ $row->description ?? '' }}</span>
    </div>
@empty
    <x-ui.empty :title="$query ? 'Eşleşen adres yok' : 'Adres yok'"
                :text="$query ? 'Başka bir arama deneyin.' : 'Bu gruba henüz adres eklenmemiş.'" />
@endforelse
