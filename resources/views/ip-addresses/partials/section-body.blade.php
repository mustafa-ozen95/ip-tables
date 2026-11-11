{{-- Bölümün blokları ve tek adresleri (bkz. section). --}}
@foreach ($section['blocks'] as $block)
    @include('ip-addresses.partials.block')
@endforeach

@forelse ($section['rows'] as $row)
    <div class="grid-row address-row"
         @if ($selected && $selected['uuid'] === $row->uuid) aria-selected="true" @endif>
        <span class="address-row__address mono truncate">{{ $row->notation }}</span>

        <span class="address-row__name truncate">
            <span @can('updateDetails', $row) @include('ip-addresses.partials.inline-edit-attrs', ['record' => $row, 'field' => 'name', 'value' => $row->name]) title="Düzenlemek için çift tıklayın" @endcan>@include('ip-addresses.partials.record-name', ['record' => $row])</span>
            @if ($selectedGroup === null)
                <span class="text-sm text-muted">· {{ $row->location }}</span>
            @endif
        </span>

        <span class="address-row__type">
            <x-ui.badge :variant="$row->group->kind->badge()">{{ $row->group->kind->label() }}</x-ui.badge>
        </span>

        <span class="address-row__description text-sm text-muted"
              @can('updateDetails', $row) @include('ip-addresses.partials.inline-edit-attrs', ['record' => $row, 'field' => 'description', 'value' => $row->description]) title="Düzenlemek için çift tıklayın" @endcan>{{ $row->description ?? '' }}</span>

        <span class="address-row__actions">
            @include('ip-addresses.partials.row-actions', ['record' => $row])
        </span>
    </div>
@empty
    @if ($section['blocks'] === [])
        <x-ui.empty :title="$query ? 'Eşleşen adres yok' : 'Adres yok'"
                    :text="$query ? 'Başka bir arama deneyin.' : 'Bu gruba henüz adres eklenmemiş.'" />
    @endif
@endforelse
