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

        return [
            'groups' => $groups,
            'allCount' => $groups->sum('ip_addresses_count'),
            'selectedGroup' => $selectedGroup,
            'query' => $query,
            'sections' => $this->sections($user, $selectedGroup, $query, $request->string('blok')->toString() ?: null, $selected['uuid'] ?? null),
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
    private function sections(User $user, ?AddressGroup $group, string $query, ?string $openBlock, ?string $selected): array
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
            'kind' => $model->group->kind,
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
        ];
    }

    /**
     * Silme, böl ve bölmeyi kaldır düğmeleri yalnız isteğin de geçireceği
     * durumda çizilir: bölünmüş bloğun ve parçanın "Sil"i reddedilecekti
     * (bkz. Ipv4BlockRules, SplitIpAddressRequest).
     *
     * @return array{canDelete: bool, canSplit: bool, canUnsplit: bool, partCount: int}
     */
    private function blockActions(User $user, IpAddress $model): array
    {
        $parts = $this->rules->isBlock($model) ? $this->rules->parts($model)->count() : 0;
        $group = $model->address_group_id;

        return [
            'canDelete' => $user->can('delete', $model) && $this->rules->deleteError($model) === null,
            'canSplit' => $this->rules->isBlock($model) && $parts === 0 && $user->can('update', $model)
                && $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'create', $group),
            'canUnsplit' => $parts > 0 && $user->can('delete', $model),
            'partCount' => $parts,
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
            default => null,
        };
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
