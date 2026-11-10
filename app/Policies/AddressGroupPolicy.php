<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AddressGroup;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;

/**
 * Grup yetkileri.
 *
 * Grup AÇMAK, adını değiştirmek ve silmek yönetici işidir: grup aynı zamanda
 * yetki sınırıdır ve sınırı çizen, sınırın içine kimin gireceğini de belirler.
 * Bu yüzden yazma uçları yalnızca Gate::before ile geçilir.
 */
class AddressGroupPolicy
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, IpAddressPolicy::RESOURCE, 'view');
    }

    public function view(User $user, AddressGroup $group): bool
    {
        return $this->resolver->allows($user, IpAddressPolicy::RESOURCE, 'view', $group->getKey());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AddressGroup $group): bool
    {
        return false;
    }

    public function delete(User $user, AddressGroup $group): bool
    {
        return false;
    }
}
