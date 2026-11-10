<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GroupKind;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\User;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\Request;

/**
 * Blok planı ekranının verisi (bkz. Ipv4BlockPlan). Blade yalnız buradan
 * geleni çizer.
 *
 * Liste ekranıyla aynı daraltma (visibleTo): görülmeyen grubun adresi
 * ağaçta da yoktur, boş görünür. Böl / bölmeyi kaldır düğmeleri yalnız
 * isteğin de geçireceği yetkide çizilir (bkz. SplitIpAddressRequest).
 */
class AddressPlanListing
{
    /** @var array<string, bool> */
    private array $allowed = [];

    public function __construct(
        private Ipv4BlockPlan $plan,
        private UserPermissionResolver $resolver,
    ) {}

    /** @return array<string, mixed> */
    public function forRequest(Request $request): array
    {
        $user = $request->user();

        $groups = AddressGroup::query()->visibleTo($user)->ordered()
            ->withCount('ipAddresses')->get();

        $selectedGroup = $groups->firstWhere('uuid', $request->query('grup'));

        $records = IpAddress::query()->visibleTo($user)
            ->when($selectedGroup, fn ($q, AddressGroup $g) => $q->whereBelongsTo($g, 'group'))
            ->with(['group', 'subGroup'])
            ->ordered()
            ->get();

        $plan = $this->plan->build($records, $request->string('blok')->toString() ?: null);
        $blocks = array_map(fn (array $block): array => [...$block, ...$this->actions($user, $block)], $plan['blocks']);

        return [
            'groups' => $groups,
            'allCount' => $groups->sum('ip_addresses_count'),
            'selectedGroup' => $selectedGroup,
            'ipv6Only' => in_array($selectedGroup?->kind, [GroupKind::Ipv6, GroupKind::LocalIpv6], true),
            'blocks' => $blocks,
            'others' => $plan['others'],
            'splitBlock' => $this->splitBlock($request, $blocks),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{canSplit: bool, canUnsplit: bool}
     */
    private function actions(User $user, array $block): array
    {
        $group = $block['record']->address_group_id;

        return [
            'canSplit' => $block['parts'] === []
                && $this->allows($user, 'update', $group) && $this->allows($user, 'create', $group),
            'canUnsplit' => $block['parts'] !== [] && $this->allows($user, 'delete', $group),
        ];
    }

    /**
     * ?pencere=bol&blok=… ile açılan böl penceresinin bloğu; yalnız
     * kullanıcının bölebildiği, ekranda görünen bir blok.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function splitBlock(Request $request, array $blocks): ?IpAddress
    {
        if ($request->query('pencere') !== 'bol') {
            return null;
        }

        $block = collect($blocks)->first(fn (array $b): bool => $b['canSplit'] && $b['record']->uuid === $request->query('blok'));

        return $block['record'] ?? null;
    }

    private function allows(User $user, string $action, int $group): bool
    {
        return $this->allowed["{$action}:{$group}"]
            ??= $this->resolver->allows($user, IpAddressPolicy::RESOURCE, $action, $group);
    }
}
