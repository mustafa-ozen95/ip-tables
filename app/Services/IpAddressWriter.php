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
            $before = $this->snapshot($ipAddress);

            $this->fill($ipAddress, $payload)->save();

            $this->recordChanges($ipAddress, $before);

            return $ipAddress;
        });
    }

    /** Tek alan (ad ya da açıklama); listede çift tıklayarak düzenleme. */
    public function updateField(IpAddress $ipAddress, string $field, ?string $value): void
    {
        DB::transaction(function () use ($ipAddress, $field, $value): void {
            $before = $this->snapshot($ipAddress);

            $ipAddress->fill([$field => $value])->save();

            $this->recordChanges($ipAddress, $before);
        });
    }

    /**
     * Kayda neyin neye döndüğü yazılır: "… · ad: “A” → “B”". Hiçbir şey
     * değişmediyse de satır düşer; kaydet'e basıldığı bilinsin.
     *
     * @param  array<string, string|null>  $before  snapshot()
     */
    private function recordChanges(IpAddress $ipAddress, array $before): void
    {
        $after = $this->snapshot($ipAddress->load(['group', 'subGroup']));
        $quote = fn (?string $v): string => $v === null || $v === '' ? '(boş)' : "“{$v}”";

        $changes = collect($after)
            ->filter(fn (?string $value, string $label): bool => $value !== $before[$label])
            ->map(fn (?string $value, string $label): string => "{$label}: {$quote($before[$label])} → {$quote($value)}")
            ->implode(' · ');

        $this->activity->record(
            ActivityAction::Updated,
            "Adres güncellendi: {$this->describe($ipAddress)}".($changes === '' ? ' (değişiklik yok)' : " · {$changes}"),
            $ipAddress,
        );
    }

    /** @return array<string, string|null> kayıtta adıyla görünen alanlar */
    private function snapshot(IpAddress $ipAddress): array
    {
        return [
            'adres' => $ipAddress->notation,
            'grup' => $ipAddress->group?->name,
            'alt grup' => $ipAddress->subGroup?->name,
            'ad' => $ipAddress->name,
            'açıklama' => $ipAddress->description,
            'vurgu' => $ipAddress->highlight?->label(),
        ];
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

    /**
     * Sıralı iki eş parçayı bir üst boya birleştirir. Kalan kayıt adı olan
     * parçadır (ikisi de adsızsa öndeki); adı, açıklaması ve alt grubu
     * birleşen parçaya geçer, öbürü silinir. Kalan kaydı döndürür.
     */
    public function merge(IpAddress $part, IpAddress $buddy, ParsedAddress $merged): IpAddress
    {
        $buddy->loadMissing('group');
        [$keep, $drop] = filled($buddy->name) ? [$buddy, $part] : [$part, $buddy];
        $before = $this->describe($part).' + '.$buddy->notation;

        DB::transaction(function () use ($keep, $drop, $merged, $before): void {
            $drop->delete();

            $keep->forceFill(['address' => $merged->address, 'prefix' => $merged->prefix, 'sort_key' => $merged->sortKey])->save();

            $this->activity->record(ActivityAction::Updated, "Parçalar birleştirildi: {$before} → {$keep->notation}", $keep);
        });

        return $keep;
    }

    /**
     * Parçayı eş parçalara böler: kayıt ilk parça olur ve adını korur,
     * ötekiler adsız açılır. Ping sonucu eski boyundandır, silinir; sonraki
     * turda yenisi gelir (bkz. GatewayPing).
     *
     * @param  list<ParsedAddress>  $pieces  sıralı, en az iki
     */
    public function subdivide(IpAddress $part, array $pieces): void
    {
        $part->loadMissing(['group', 'subGroup']);
        [$first, $last] = [$pieces[0], $pieces[array_key_last($pieces)]];

        DB::transaction(function () use ($part, $pieces, $first, $last): void {
            $before = $this->describe($part);

            $part->forceFill([
                'address' => $first->address, 'prefix' => $first->prefix, 'sort_key' => $first->sortKey,
                'gateway_up' => null, 'gateway_checked_at' => null,
            ])->save();

            foreach (array_slice($pieces, 1) as $piece) {
                $this->fill(new IpAddress, [
                    'group' => $part->group,
                    'subGroup' => $part->subGroup,
                    'parsed' => $piece,
                    'details' => ['name' => null, 'description' => null],
                ])->save();
            }

            $this->activity->record(ActivityAction::Updated, "Parça bölündü: {$before} → ".count($pieces)." × /{$first->prefix}"
                ." ({$first->notation()} … {$last->notation()})", $part);
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
