<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;

/**
 * Adres yetkileri GRUPTAN okunur: rolün şablonu ve kişiye özel istisnalar
 * (bkz. UserPermissionResolver). Yönetici her kontrolü geçer.
 */
class IpAddressPolicy
{
    /** config/permissions.php'deki kaynak anahtarı. */
    public const RESOURCE = 'address_group';

    public function __construct(private UserPermissionResolver $resolver) {}

    /*
     * En az bir grubu görebilen listeye girer; hangi adresleri gördüğünü
     * visibleTo() daraltır. Hiç grubu olmayan boş bir liste değil 403 alır.
     */
    public function viewAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, self::RESOURCE, 'view');
    }

    public function view(User $user, IpAddress $ipAddress): bool
    {
        return $this->can($user, 'view', $ipAddress);
    }

    /* Hangi gruba eklenebileceğini istek doğrular (StoreIpAddressRequest). */
    public function create(User $user): bool
    {
        return $this->resolver->allowsAny($user, self::RESOURCE, 'create');
    }

    public function update(User $user, IpAddress $ipAddress): bool
    {
        return $this->can($user, 'update', $ipAddress);
    }

    /*
     * Yalnız ad ve açıklama (yerinde düzenleme, bkz. IpAddressFieldController).
     * "Düzenle" bunu kapsar; tersi değil: adres, grup, vurgu, bölme ister.
     */
    public function updateDetails(User $user, IpAddress $ipAddress): bool
    {
        return $this->can($user, 'update_details', $ipAddress) || $this->can($user, 'update', $ipAddress);
    }

    public function delete(User $user, IpAddress $ipAddress): bool
    {
        return $this->can($user, 'delete', $ipAddress);
    }

    /* Gateway'e elle ping: dışarıya paket çıkar, "görüntüle" kapsamaz. */
    public function ping(User $user, IpAddress $ipAddress): bool
    {
        return $this->can($user, 'ping', $ipAddress);
    }

    /*
     * Excel çıktısı: veri panelden çıkar, "görüntüle" kapsamaz. Hangi
     * grupların dosyaya gireceğini IpAddress::allowedTo('export') daraltır.
     */
    public function exportAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, self::RESOURCE, 'export');
    }

    private function can(User $user, string $action, IpAddress $ipAddress): bool
    {
        return $this->resolver->allows($user, self::RESOURCE, $action, $ipAddress->address_group_id);
    }
}
