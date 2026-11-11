@props([
    'id',
    'window',
    'title',
    'action',
    'method' => 'POST',
    'submit' => 'Kaydet',
    'open' => false,
    'record' => null,
    'groupOptions' => [],
    'subGroupOptions' => [],
    'subGroupMap' => [],
    'selectedGroup' => null,
])

{{--
    Adres penceresi (ekle / düzenle). _pencere doğrulama hatasından sonra
    hangi pencerenin yeniden açılacağını söyler (bkz. IpAddressListing::window).
--}}
<x-ui.modal :id="$id" :title="$title" :open="$open">
    <form method="POST" action="{{ $action }}" autocomplete="off" data-inline>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="_pencere" value="{{ $window }}">

        <div class="modal__body" data-conditional-form>
            <x-ui.errors class="modal__alert" />

            <x-form.field name="group" label="Grup" hint="Grubun türü hangi adreslerin girebileceğini belirler.">
                <x-form.select name="group" :options="$groupOptions"
                               :selected="$record?->group?->uuid ?? $selectedGroup" />
            </x-form.field>

            @if (count($subGroupOptions) > 1)
                <x-form.field name="sub_group" label="Alt grup" hint="Grubun içindeki bölme; listede başlık olarak görünür. Yetki taşımaz.">
                    <x-form.select name="sub_group" :options="$subGroupOptions"
                                   :selected="$record?->subGroup?->uuid"
                                   data-linked="group"
                                   data-linked-options="{{ json_encode($subGroupMap) }}" />
                </x-form.field>
            @endif

            <x-form.field name="address" label="Adres"
                          hint="IPv4’te yalnız /24 blok (192.0.2.0/24); küçük parçalar bloğun ayrıntısındaki “Böl” ile açılır. IPv6’da tek adres ya da blok (2001:db8::1).">
                <x-form.input name="address" :value="$record?->notation" mono required maxlength="64"
                              spellcheck="false" />
            </x-form.field>

            <x-form.field name="name" label="Ad" hint="Cihaz, sunucu ya da hizmetin adı.">
                <x-form.input name="name" :value="$record?->name" maxlength="120" />
            </x-form.field>

            <x-form.field name="highlight" label="Vurgu rengi" hint="Seçilirse ad listede kalın ve bu renkte yazılır; dikkat çekmesi gerekenler için.">
                {{-- Açılır liste rengi göstermez; seçenekler adın listede görüneceği renkte yan yana durur. --}}
                <div class="choices" role="radiogroup" aria-label="Vurgu rengi">
                    @foreach (App\Enums\Highlight::options() as $value => $label)
                        <label class="checkbox">
                            <input type="radio" name="highlight" value="{{ $value }}" @if ($value === '') id="highlight" @endif
                                   @checked((string) old('highlight', $record?->highlight?->value) === (string) $value)>
                            @if ($value === '')
                                {{ $label }}
                            @else
                                <span class="dot dot--{{ $value }}" aria-hidden="true"></span>
                                <span class="highlight highlight--{{ $value }}">{{ $label }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>
            </x-form.field>

            <x-form.field name="description" label="Açıklama">
                <x-form.textarea name="description" :value="$record?->description" maxlength="1000" />
            </x-form.field>
        </div>

        <x-ui.modal-actions :submit="$submit" />
    </form>
</x-ui.modal>
