<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\SubGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Adresi yazan TEK yer. Adres, önek ve sıralama anahtarı her seferinde
 * çözümleyiciden (IpAddressParser) gelir; her yazım etkinlik kaydına geçer.
 * Kayıt ve denetim satırı birlikte yazılır ya da hiçbiri yazılmaz.
 */
class IpAddressWriter
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @param  array{group: AddressGroup, subGroup: SubGroup|null, parsed: ParsedAddress, details: array<string, string|null>}  $payload */
    public function create(array $payload): IpAddress
    {
        return DB::transaction(function () use ($payload): IpAddress {
            $ipAddress = $this->fill(new IpAddress, $payload);
            $ipAddress->save();

            $this->activity->record(ActivityAction::Created, "Adres eklendi: {$this->describe($ipAddress)}", $ipAddress);

            return $ipAddress;
        });
    }

    /** @param  array{group: AddressGroup, subGroup: SubGroup|null, parsed: ParsedAddress, details: array<string, string|null>}  $payload */
    public function update(IpAddress $ipAddress, array $payload): IpAddress
    {
        return DB::transaction(function () use ($ipAddress, $payload): IpAddress {
            $this->fill($ipAddress, $payload)->save();

            $this->activity->record(ActivityAction::Updated, "Adres güncellendi: {$this->describe($ipAddress)}", $ipAddress);

            return $ipAddress;
        });
    }

    public function delete(IpAddress $ipAddress, string $reason): void
    {
        DB::transaction(function () use ($ipAddress, $reason): void {
            $this->activity->record(ActivityAction::Deleted, "Adres silindi: {$this->describe($ipAddress)}", $ipAddress, $reason);

            $ipAddress->delete();
        });
    }

    /**
     * Bloğu parçalara böler (bkz. Ipv4BlockRules). Parçalar bloğun grubunda
     * ve alt grubunda, adsız açılır; atama sonra adlarından yapılır. Kayda
     * parça başına değil bölme başına TEK satır düşer.
     *
     * @param  list<ParsedAddress>  $parts
     */
    public function split(IpAddress $block, array $parts, string $layout): void
    {
        DB::transaction(function () use ($block, $parts, $layout): void {
            foreach ($parts as $parsed) {
                $this->fill(new IpAddress, [
                    'group' => $block->group,
                    'subGroup' => $block->subGroup,
                    'parsed' => $parsed,
                    'details' => ['name' => null, 'description' => null],
                ])->save();
            }

            $this->activity->record(ActivityAction::Updated, 'Blok bölündü: '.$this->describe($block)
                .' → '.count($parts)." parça ({$layout})", $block);
        });
    }

    /** @param  Collection<int, IpAddress>  $parts */
    public function unsplit(IpAddress $block, Collection $parts, string $reason): void
    {
        DB::transaction(function () use ($block, $parts, $reason): void {
            $this->activity->record(ActivityAction::Deleted, 'Blok bölmesi kaldırıldı: '.$this->describe($block)
                .' · '.$parts->count().' parça silindi', $block, $reason);

            IpAddress::query()->whereKey($parts->modelKeys())->delete();
        });
    }

    /** @param  array{group: AddressGroup, subGroup: SubGroup|null, parsed: ParsedAddress, details: array<string, string|null>}  $payload */
    private function fill(IpAddress $ipAddress, array $payload): IpAddress
    {
        $parsed = $payload['parsed'];

        $ipAddress->fill($payload['details']);
        $ipAddress->group()->associate($payload['group']);
        $ipAddress->subGroup()->associate($payload['subGroup']);
        $ipAddress->forceFill([
            'address' => $parsed->address,
            'prefix' => $parsed->prefix,
            'sort_key' => $parsed->sortKey,
        ]);

        return $ipAddress;
    }

    private function describe(IpAddress $ipAddress): string
    {
        $name = $ipAddress->name ? " ({$ipAddress->name})" : '';

        return "{$ipAddress->group->name} · {$ipAddress->notation}{$name}";
    }
}
