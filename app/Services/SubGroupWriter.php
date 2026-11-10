<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\AddressGroup;
use App\Models\SubGroup;
use Illuminate\Support\Facades\DB;

/**
 * Alt grubu yazan TEK yer; her yazım denetim satırıyla birlikte.
 *
 * SİLMEK ADRES SİLMEZ: alt grup kaldırılınca adresler grupta, başlıksız
 * kalır (bkz. sub_groups göçü, nullOnDelete).
 */
class SubGroupWriter
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @param  array<string, mixed>  $payload */
    public function create(AddressGroup $group, array $payload): SubGroup
    {
        return DB::transaction(function () use ($group, $payload): SubGroup {
            /* Yeni alt grup listenin SONUNA girer; yerini sürükleyerek verirsiniz. */
            $subGroup = $group->subGroups()->create([
                ...$payload,
                'position' => (int) $group->subGroups()->max('position') + 1,
            ]);

            $this->activity->record(ActivityAction::Created, "Alt grup açıldı: {$group->name} · {$subGroup->name}", $group);

            return $subGroup;
        });
    }

    /** @param  array<string, mixed>  $payload */
    public function update(SubGroup $subGroup, array $payload): SubGroup
    {
        return DB::transaction(function () use ($subGroup, $payload): SubGroup {
            $subGroup->update($payload);

            $this->activity->record(ActivityAction::Updated, "Alt grup güncellendi: {$subGroup->group->name} · {$subGroup->name}", $subGroup->group);

            return $subGroup;
        });
    }

    public function delete(SubGroup $subGroup, ?string $reason): void
    {
        DB::transaction(function () use ($subGroup, $reason): void {
            $group = $subGroup->group;
            $count = $subGroup->ipAddresses()->count();

            $subGroup->delete();

            $this->activity->record(
                ActivityAction::Deleted,
                "Alt grup kaldırıldı: {$group->name} · {$subGroup->name} ({$count} adres grupta kaldı)",
                $group,
                $reason,
            );
        });
    }

    /**
     * Gelen liste grubun TÜM alt grupları olmayabilir: adres listesi boş
     * alt grubu çizmiyor. Gelmeyenler yerinde kalır, gelenler kendi eski
     * yuvalarına yeni sırayla oturur; sonra hepsi baştan numaralanır.
     *
     * @param  list<string>  $uuids
     */
    public function reorder(AddressGroup $group, array $uuids): void
    {
        DB::transaction(function () use ($group, $uuids): void {
            $subGroups = $group->subGroups()->pluck('id', 'uuid');
            $gelen = array_values(array_filter($uuids, fn (string $uuid): bool => $subGroups->has($uuid)));
            $sira = $gelen;
            $dizilim = $subGroups->keys()->map(function (string $uuid) use ($gelen, &$sira): string {
                return in_array($uuid, $gelen, true) ? array_shift($sira) : $uuid;
            });

            foreach ($dizilim->values() as $i => $uuid) {
                SubGroup::query()->whereKey($subGroups[$uuid])->update(['position' => $i + 1]);
            }

            $this->activity->record(ActivityAction::Updated, "Alt gruplar yeniden sıralandı: {$group->name}", $group);
        });
    }
}
