<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GroupKind;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use Illuminate\Http\Request;

/**
 * Blok planı ekranının verisi (bkz. Ipv4BlockPlan). Blade yalnız buradan
 * geleni çizer.
 *
 * Liste ekranıyla aynı daraltma (visibleTo): görülmeyen grubun adresi
 * ağaçta da yoktur, boş görünür. Boş yaprak "kayıt yok" demektir, "kimse
 * kullanmıyor" değil — ekran bunu yazar.
 */
class AddressPlanListing
{
    public function __construct(private Ipv4BlockPlan $plan) {}

    /** @return array<string, mixed> */
    public function forRequest(Request $request): array
    {
        $user = $request->user();

        $groups = AddressGroup::query()->visibleTo($user)->ordered()
            ->withCount('ipAddresses')->get();

        $selectedGroup = $groups->firstWhere('uuid', $request->query('grup'));

        $records = IpAddress::query()->visibleTo($user)
            ->when($selectedGroup, fn ($q, AddressGroup $g) => $q->whereBelongsTo($g, 'group'))
            ->with('group')
            ->ordered()
            ->get();

        return [
            'groups' => $groups,
            'allCount' => $groups->sum('ip_addresses_count'),
            'selectedGroup' => $selectedGroup,
            'ipv6Only' => in_array($selectedGroup?->kind, [GroupKind::Ipv6, GroupKind::LocalIpv6], true),
            ...$this->plan->build($records),
        ];
    }
}
