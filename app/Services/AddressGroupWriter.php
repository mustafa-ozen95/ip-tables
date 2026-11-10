<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\AddressGroup;
use App\Services\Authorization\RolePermissions;
use Illuminate\Support\Facades\DB;

/**
 * Grubu yazan TEK yer. Grup, rol şablonları ve denetim satırı birlikte
 * yazılır ya da hiçbiri yazılmaz: şablon grubu ADIYLA tanıyor, ad değişip
 * şablon eski kalırsa o gruptaki rol yetkisi sessizce düşerdi.
 */
class AddressGroupWriter
{
    public function __construct(
        private ActivityRecorder $activity,
        private RolePermissions $roles,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function create(array $payload): AddressGroup
    {
        return DB::transaction(function () use ($payload): AddressGroup {
            /* Yeni grup listenin SONUNA girer; yerini sürükleyerek verirsiniz. */
            $group = AddressGroup::create([
                ...$payload,
                'position' => (int) AddressGroup::query()->max('position') + 1,
            ]);

            $this->activity->record(ActivityAction::Created, "Grup açıldı: {$group->name}", $group);

            return $group;
        });
    }

    /** @param  array<string, mixed>  $payload */
    public function update(AddressGroup $group, array $payload): AddressGroup
    {
        return DB::transaction(function () use ($group, $payload): AddressGroup {
            $eskiAd = $group->name;

            $group->update($payload);

            $this->roles->grupYenidenAdlandirildi($eskiAd, $group->name);

            $this->activity->record(
                ActivityAction::Updated,
                $eskiAd === $group->name
                    ? "Grup güncellendi: {$group->name}"
                    : "Grup yeniden adlandırıldı: {$eskiAd} → {$group->name}",
                $group,
            );

            return $group;
        });
    }

    public function delete(AddressGroup $group, string $reason): void
    {
        DB::transaction(function () use ($group, $reason): void {
            $count = $group->ipAddresses()->count();

            $group->delete();

            /* Şablonda adı kalmasın: yetki vermiyor ama her istekte uyarı yazıyordu. */
            $this->roles->grupSilindi($group->name);

            $this->activity->record(ActivityAction::Deleted, "Grup silindi: {$group->name} ({$count} adres)", $group, $reason);
        });
    }

    /**
     * Gelen dizideki uuid'ler 1'den başlayarak numaralanır; listede olmayan
     * grup olduğu gibi kalır.
     *
     * @param  list<string>  $uuids
     */
    public function reorder(array $uuids): void
    {
        DB::transaction(function () use ($uuids): void {
            $groups = AddressGroup::query()->pluck('id', 'uuid');
            $position = 1;

            foreach ($uuids as $uuid) {
                if ($groups->has($uuid)) {
                    AddressGroup::query()->whereKey($groups[$uuid])->update(['position' => $position++]);
                }
            }

            $this->activity->record(ActivityAction::Updated, 'Gruplar yeniden sıralandı');
        });
    }
}
