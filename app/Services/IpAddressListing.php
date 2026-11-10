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
 * Her sorgu kullanıcıya göre DARALTILIR (visibleTo): görülmeyen grubun
 * adresi ne listede, ne sayıda, ne ayrıntıda görünür. Sayfa parametreleri
 * (grup, kayıt) uuid taşır.
 */
class IpAddressListing
{
    public function __construct(private UserPermissionResolver $resolver) {}

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
            'sections' => $this->sections($user, $selectedGroup, $query),
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
     * tek bölümdür ve satırda grubun adı da yazar.
     *
     * @return array<int, array{title: string|null, rows: Collection<int, IpAddress>}>
     */
    private function sections(User $user, ?AddressGroup $group, string $query): array
    {
        $rows = IpAddress::query()->visibleTo($user)
            ->when($group, fn ($q, AddressGroup $g) => $q->whereBelongsTo($g, 'group'))
            ->search($query)
            ->with(['group', 'subGroup'])
            ->ordered()
            ->get();

        if ($group === null || $group->subGroups->isEmpty()) {
            return [['title' => null, 'rows' => $rows]];
        }

        $byGroup = $rows->groupBy('sub_group_id');

        $sections = $group->subGroups
            ->map(fn (SubGroup $sub): array => ['title' => $sub->name, 'rows' => $byGroup->get($sub->id, new Collection)])
            ->all();

        $sections[] = ['title' => 'Alt grupsuz', 'rows' => $byGroup->get('', new Collection)];

        return array_values(array_filter($sections, fn (array $s): bool => $s['rows']->isNotEmpty()))
            ?: [['title' => null, 'rows' => new Collection]];
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
            'canDelete' => $user->can('delete', $model),
        ];
    }

    /** Doğrulama hatasından sonra pencere, gönderildiği hâliyle yeniden açılır. */
    private function window(Request $request, ?array $selected): ?string
    {
        $window = $request->old('_pencere', $request->query('pencere'));

        return match ($window) {
            'ekle' => 'ekle',
            'duzenle' => $selected !== null && $selected['canUpdate'] ? 'duzenle' : null,
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
