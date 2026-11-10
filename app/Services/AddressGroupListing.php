<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AddressGroup;
use App\Models\SubGroup;
use App\Models\User;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Gruplar ekranının verisi. Blade yalnız buradan geleni çizer.
 *
 * Ekran yalnız yöneticiye açık (bkz. AddressGroupPolicy); daraltma yok,
 * ama görüntüye giden hiçbir yapı sıralı birincil anahtar taşımaz.
 */
class AddressGroupListing
{
    public function __construct(private UserPermissionResolver $resolver) {}

    /** @return array<string, mixed> */
    public function forRequest(Request $request): array
    {
        /*
         * SAYFALAMA YOK: gruplar sürüklenerek sıralanıyor ve sıra tek bir
         * listede anlamlı; iki sayfaya bölünmüş liste sürüklenemez. Grup
         * sayısı zaten onlarla ölçülüyor.
         */
        $groups = AddressGroup::query()
            ->search($request->string('q')->squish()->value() ?: null)
            ->withCount('ipAddresses')
            ->with(['subGroups' => fn ($q) => $q->withCount('ipAddresses')])
            ->ordered()
            ->get();

        return [
            'groups' => $groups,
            'viewerCounts' => $this->viewerCounts($groups),
            'query' => $request->string('q')->value(),
            /* Hepsi KAPALI gelir; yalnız ?acik= ile istenen açık: alt grup işinden dönüşte. */
            'acikGrup' => $request->string('acik')->value() ?: null,
            'pencere' => $this->subGroupWindow($request, $groups),
        ];
    }

    /**
     * Alt grup penceresi: ekleme (?pencere=ekle&grup=) ya da düzenleme
     * (?pencere=duzenle&alt=). Pencere sunucudan açık gelir, doğrulama
     * hatasında da öyle.
     *
     * @param  Collection<int, AddressGroup>  $groups
     * @return array{baslik: string, action: string, method: string, ad: ?string, grupAdi: string}|null
     */
    private function subGroupWindow(Request $request, Collection $groups): ?array
    {
        $pencere = $request->string('pencere')->value();

        if ($pencere === 'ekle') {
            $group = $groups->firstWhere('uuid', $request->string('grup')->value());

            return $group === null ? null : [
                'baslik' => "{$group->name} · yeni alt grup",
                'action' => route('groups.sub.store', $group),
                'method' => 'POST',
                'ad' => null,
                'grupAdi' => $group->name,
            ];
        }

        if ($pencere === 'duzenle') {
            $subGroup = SubGroup::query()->with('group')->where('uuid', $request->string('alt')->value())->first();

            return $subGroup === null ? null : [
                'baslik' => "{$subGroup->group->name} · {$subGroup->name}",
                'action' => route('groups.sub.update', [$subGroup->group, $subGroup]),
                'method' => 'PUT',
                'ad' => $subGroup->name,
                'grupAdi' => $subGroup->group->name,
            ];
        }

        return null;
    }

    /**
     * "Kaç kullanıcı görebiliyor" — sayaçla değil çözümleyiciyle.
     *
     * İzin genel de olabilir, gruba özel de, ve bir yasak satırı ikisini de
     * keser; karar çözümleyicinin işi.
     *
     * Dizi UUID ile anahtarlanır: görüntüye giden hiçbir yapı sıralı
     * birincil anahtar taşımaz, hesapta bile. Karar iç anahtarla verilir.
     *
     * @param  Collection<int, AddressGroup>  $groups
     * @return array<string, int> grup uuid => görebilen kullanıcı sayısı
     */
    private function viewerCounts(Collection $groups): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        $users = User::query()->with('permissions', 'role')->get();

        return $groups
            ->mapWithKeys(fn (AddressGroup $group): array => [
                $group->uuid => $users->filter(fn (User $user): bool => $this->resolver->allows(
                    $user, IpAddressPolicy::RESOURCE, 'view', $group->getKey(),
                ))->count(),
            ])
            ->all();
    }
}
