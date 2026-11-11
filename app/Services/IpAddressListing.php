<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\SubGroup;
use App\Models\User;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Ana ekranın verisi. Blade yalnız buradan geleni çizer.
 *
 * IPv4 /24 bloklar listede katlanır satırdır, parçaları altında (bkz.
 * Ipv4BlockPlan); diğer kayıtlar düz satırdır. Arama sonucu düz listedir:
 * eşleşen parça, bloğu eşleşmese de görünmeli.
 *
 * Ayrıntı sütunu yok: satırın sonunda Düzenle, Böl ve ⋮ durur. ⋮ kaydın
 * bilgisini ve kalan işlemleri pencerede açar ("islemler"); o işlemlerin
 * kuralları yalnız seçili kayıt için hesaplanır (selected).
 *
 * Her sorgu kullanıcıya göre DARALTILIR (visibleTo): görülmeyen grubun
 * adresi ne listede, ne sayıda, ne ayrıntıda görünür. Sayfa parametreleri
 * (grup, kayıt) uuid taşır.
 */
class IpAddressListing
{
    public function __construct(
        private UserPermissionResolver $resolver,
        private Ipv4BlockPlan $plan,
        private Ipv4BlockRules $rules,
        private Ipv4SplitLayout $layout,
    ) {}

    /** @return array<string, mixed> */
    public function forRequest(Request $request): array
    {
        $user = $request->user();
        $query = $request->string('q')->trim()->limit(100, '')->toString();

        $groups = AddressGroup::query()->visibleTo($user)->ordered()
            ->withCount('ipAddresses')->with('subGroups')->get();

        $selectedGroup = $groups->firstWhere('uuid', $request->query('grup'));
        $selected = $this->selected($request, $user);
        $window = $this->window($request, $selected);
        $creatable = $this->creatableGroups($user, $groups);
        $rowCan = $this->rowAbilities($user, $groups);

        return [
            'groups' => $groups,
            'allCount' => $groups->sum('ip_addresses_count'),
            'selectedGroup' => $selectedGroup,
            'query' => $query,
            'sections' => $this->sections($user, $selectedGroup, $query, $request->string('blok')->toString() ?: null, $selected['uuid'] ?? null, $rowCan),
            'rowCan' => $rowCan,
            'selected' => $selected,
            'window' => $window,
            'canCreate' => $creatable !== [],
            'groupOptions' => $creatable,
            'updateGroupOptions' => $selected === null ? [] : $this->updateGroupOptions($creatable, $selected['model']),
            'subGroupOptions' => $this->subGroupOptions($groups),
            'subGroupMap' => $this->subGroupMap($groups),
        ];
    }

    /**
     * Listedeki bölümler. Grup seçiliyse alt gruplarına göre bölünür; "Hepsi"
     * tek bölümdür ve satırda grubun adı da yazar. Parça, alt grubu ne olursa
     * olsun bloğunun altında durur.
     *
     * @return array<int, array{title: string|null, blocks: list<array<string, mixed>>, rows: Collection<int, IpAddress>}>
     */
    /**
     * @param  array<int, array{update: bool, split: bool}>  $rowCan
     */
    private function sections(User $user, ?AddressGroup $group, string $query, ?string $openBlock, ?string $selected, array $rowCan): array
    {
        $records = IpAddress::query()->visibleTo($user)
            ->when($group, fn ($q, AddressGroup $g) => $q->whereBelongsTo($g, 'group'))
            ->search($query)
            ->with(['group', 'subGroup'])
            ->ordered()
            ->get();

        if ($query !== '') {
            return [['title' => null, 'blocks' => [], 'rows' => $records]];
        }

        $plan = $this->plan->build($records, $openBlock, $selected);
        $plan['blocks'] = array_map(fn (array $b): array => [
            ...$b, 'canSplit' => $b['hasFree'] && ($rowCan[$b['record']->address_group_id]['split'] ?? false),
        ], $plan['blocks']);
        $rows = new Collection($plan['others']);

        if ($group === null || $group->subGroups->isEmpty()) {
            return [['title' => null, 'blocks' => $plan['blocks'], 'rows' => $rows]];
        }

        $blocksBy = collect($plan['blocks'])->groupBy(fn (array $b): string => (string) $b['record']->sub_group_id);
        $rowsBy = $rows->groupBy(fn (IpAddress $r): string => (string) $r->sub_group_id);
        $section = fn (?string $title, string $key): array => [
            'title' => $title,
            'blocks' => $blocksBy->get($key, collect())->all(),
            'rows' => $rowsBy->get($key, new Collection),
        ];

        $sections = $group->subGroups->map(fn (SubGroup $sub): array => $section($sub->name, (string) $sub->id))->all();
        $sections[] = $section('Alt grupsuz', '');

        return array_values(array_filter($sections, fn (array $s): bool => $s['blocks'] !== [] || $s['rows']->isNotEmpty()))
            ?: [['title' => null, 'blocks' => [], 'rows' => new Collection]];
    }

    /** @return array<string, mixed>|null */
    private function selected(Request $request, User $user): ?array
    {
        $uuid = $request->query('kayit');

        $model = is_string($uuid) ? IpAddress::query()->visibleTo($user)
            ->with(['group', 'subGroup'])->where('uuid', $uuid)->first() : null;

        if ($model === null) {
            return null;
        }

        return [
            'model' => $model,
            'uuid' => $model->uuid,
            'notation' => $model->notation,
            'groupName' => $model->location,
            'facts' => [
                'Adres' => $model->address,
                'Önek' => '/'.$model->prefix,
                'Ad' => $model->name,
                'Eklendi' => $model->created_at?->translatedFormat('d.m.Y H:i'),
                'Güncellendi' => $model->updated_at?->translatedFormat('d.m.Y H:i'),
            ],
            'description' => $model->description,
            'canUpdate' => $user->can('update', $model),
            ...$this->blockActions($user, $model),
            ...$this->partActions($user, $model),
        ];
    }

    /**
     * Silme, böl ve bölmeyi kaldır düğmeleri yalnız isteğin de geçireceği
     * durumda çizilir: bölünmüş bloğun ve parçanın "Sil"i reddedilecekti
     * (bkz. Ipv4BlockRules, SplitIpAddressRequest). Böl, bloğun boş alanı
     * kaldıkça durur; pencere o alanı satır satır önerir.
     *
     * @return array<string, mixed>
     */
    private function blockActions(User $user, IpAddress $model): array
    {
        $block = $this->rules->isBlock($model);
        $parts = $block ? $this->rules->parts($model) : new Collection;
        $free = $block ? $this->layout->free($this->rules->taken($model, $parts)) : [];
        $canSplit = $free !== [] && $user->can('update', $model)
            && $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'create', $model->address_group_id);

        return [
            'canDelete' => $user->can('delete', $model) && $this->rules->deleteError($model) === null,
            'canSplit' => $canSplit,
            'canUnsplit' => $parts->isNotEmpty() && $user->can('delete', $model),
            'partCount' => $parts->count(),
            'split' => $canSplit ? $this->splitForm($model, $free) : null,
        ];
    }

    /**
     * Böl penceresi. Tek satır, üç liste ve bu sırayla: subnet, kaç parça,
     * o kadar parçanın art arda sığdığı boş aralık. Adet subnete, aralık
     * adede BAĞLIDIR (data-linked, bkz. conditional-field.js); zincir adet
     * listesinin change olayıyla yürür. Adet değeri subneti de taşır ("29:2"):
     * aralık listesi tek kaynağa bakar. Farklı boy için pencere yeniden
     * açılır; kalan boş alan orada yine listelenir. Betik çalışmazsa hepsi
     * görünür; istek yine sınanır.
     *
     * Seçim hatadan ya da önizlemeden dönüşte gönderildiği gibi gelir.
     *
     * @param  list<array{start: int, end: int}>  $free
     * @return array<string, mixed>
     */
    private function splitForm(IpAddress $block, array $free): array
    {
        $network = $block->address;
        $prefixes = $counts = $slots = ['' => '—'];
        $countMap = $slotMap = ['' => ['']];

        foreach (range(Ipv4SplitLayout::MIN_PREFIX, Ipv4SplitLayout::SPLIT_MAX_PREFIX) as $prefix) {
            $longest = $this->layout->longestRun($free, $prefix);

            if ($longest === 0) {
                continue;
            }

            $size = $this->layout->size($prefix);
            /* Kullanılabilir: ağ, gateway ve broadcast dışı (/29: .1 gateway, .2–.6 müşteri). */
            $prefixes[$prefix] = "/{$prefix} · ".($size - 3).' kullanılabilir · '.count($this->layout->slots($free, $prefix)).' boş yer';
            $countMap[$prefix] = [];

            foreach (range(1, $longest) as $count) {
                $key = "{$prefix}:{$count}";
                $countMap[$prefix][] = $key;
                $counts[$key] = "{$count} parça · ".($size * $count).' adres';
                $slotMap[$key] = [''];

                foreach ($this->layout->ranges($free, $prefix, $count) as $start) {
                    $end = $start + $size * $count - 1;
                    $slots["{$start}-{$end}"] = $this->layout->address($network, $start).' – .'.$end;
                    $slotMap[$key][] = "{$start}-{$end}";
                }
            }
        }

        $old = session()->getOldInput('ranges.0');
        $row = [
            'prefix' => (string) ($old['prefix'] ?? ''),
            'slot' => (string) ($old['slot'] ?? ''),
            'count' => (string) ($old['count'] ?? ''),
        ];

        return [
            'free' => implode(', ', array_map(
                fn (array $f): string => $this->layout->address($network, $f['start']).' – .'.$f['end'].' ('.($f['end'] - $f['start'] + 1).' adres)',
                $free,
            )),
            'row' => $row,
            'prefixes' => $prefixes,
            'counts' => $counts,
            'countMap' => $countMap,
            'slots' => $slots,
            'slotMap' => $slotMap,
            'preview' => session('splitPreview'),
        ];
    }

    /**
     * Parçanın birleştir ve ikiye ayır düğmeleri; parça değilse ya da
     * kural geçirmiyorsa çizilmez.
     *
     * @return array{canMerge: bool, mergeLabel: string|null, canHalve: bool, halveLabel: string|null}
     */
    private function partActions(User $user, IpAddress $model): array
    {
        if ($this->rules->blockOf($model) === null) {
            return ['canMerge' => false, 'mergeLabel' => null, 'canHalve' => false, 'halveLabel' => null];
        }

        $canMerge = $user->can('update', $model) && $user->can('delete', $model) && $this->rules->mergeError($model) === null;
        $canHalve = $user->can('update', $model) && $this->rules->halveError($model) === null
            && $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'create', $model->address_group_id);
        $half = '/'.($model->prefix + 1);

        return [
            'canMerge' => $canMerge,
            'mergeLabel' => $canMerge ? $model->notation.' + '.$this->rules->buddyNotation($model).' → '.$this->rules->mergedNotation($model) : null,
            'canHalve' => $canHalve,
            'halveLabel' => $canHalve ? "{$model->notation} → 2 × {$half}" : null,
        ];
    }

    /** Doğrulama hatasından sonra pencere, gönderildiği hâliyle yeniden açılır. */
    private function window(Request $request, ?array $selected): ?string
    {
        $window = $request->old('_pencere', $request->query('pencere'));

        return match ($window) {
            'ekle' => 'ekle',
            'duzenle' => $selected !== null && $selected['canUpdate'] ? 'duzenle' : null,
            'bol' => $selected !== null && $selected['canSplit'] ? 'bol' : null,
            'islemler' => $selected !== null ? 'islemler' : null,
            default => null,
        };
    }

    /**
     * Satır sonundaki düğmeler için grup başına yetki (grup kimliği =>
     * düzenle, böl). Yetki gruba bağlı; satır satır sorulmaz. Böl kayıt
     * açar: düzenle yanında ekle de gerekir (bkz. SplitIpAddressRequest).
     *
     * @param  Collection<int, AddressGroup>  $groups
     * @return array<int, array{update: bool, split: bool}>
     */
    private function rowAbilities(User $user, Collection $groups): array
    {
        return $groups->mapWithKeys(function (AddressGroup $g) use ($user): array {
            $update = $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'update', $g->getKey());

            return [$g->getKey() => [
                'update' => $update,
                'split' => $update && $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'create', $g->getKey()),
            ]];
        })->all();
    }

    /**
     * Kullanıcının adres ekleyebildiği gruplar (uuid => "Ad (tür)").
     *
     * @return array<string, string>
     */
    private function creatableGroups(User $user, Collection $groups): array
    {
        return $groups
            ->filter(fn (AddressGroup $g): bool => $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'create', $g->getKey()))
            ->mapWithKeys(fn (AddressGroup $g): array => [$g->uuid => "{$g->name} ({$g->kind->label()})"])
            ->all();
    }

    /**
     * Düzenlemede adresin kendi grubu her zaman seçilebilir (orada "ekle"
     * yetkisi olmasa da "düzenle" yetkisiyle kalabilir).
     *
     * @param  array<string, string>  $creatable
     * @return array<string, string>
     */
    private function updateGroupOptions(array $creatable, IpAddress $model): array
    {
        $own = $model->group;

        return [$own->uuid => "{$own->name} ({$own->kind->label()})"] + $creatable;
    }

    /** @return array<string, string> */
    private function subGroupOptions(Collection $groups): array
    {
        return ['' => '— Alt grup yok —'] + $groups
            ->flatMap(fn (AddressGroup $g) => $g->subGroups)
            ->mapWithKeys(fn (SubGroup $sub): array => [$sub->uuid => $sub->name])
            ->all();
    }

    /**
     * Grup seçimine göre gösterilecek alt gruplar (bkz. conditional-field.js,
     * data-linked-options).
     *
     * @return array<string, array<int, string>>
     */
    private function subGroupMap(Collection $groups): array
    {
        return $groups
            ->mapWithKeys(fn (AddressGroup $g): array => [
                $g->uuid => ['', ...$g->subGroups->pluck('uuid')->all()],
            ])
            ->all();
    }
}
