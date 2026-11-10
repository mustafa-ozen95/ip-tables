<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GroupKind;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\Searchable;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Database\Factories\AddressGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adres grubu — IPv4, Yerel IPv6, "Müşteri A"...
 *
 * Yetki bu seviyede verilir: kullanıcıya adres adres izin vermek yerine grup
 * verilir. Yeni bir adres eklendiğinde izin listesini güncellemek gerekmez;
 * grubu gören yeni adresi de görür. Türü (kind) gruba hangi adreslerin
 * girebileceğini söyler (bkz. GroupKind).
 */
class AddressGroup extends Model
{
    /** @use HasFactory<AddressGroupFactory> */
    use HasFactory;

    use HasUuid;
    use Searchable;

    protected $fillable = [
        'name',
        'kind',
        'description',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'kind' => GroupKind::class,
            'position' => 'integer',
        ];
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class)->ordered();
    }

    /**
     * Grubun bölmeleri — kenar çubuğunda grubun altında, listede başlık.
     *
     * Yetki taşımazlar; grup neyse o (bkz. SubGroup).
     *
     * @return HasMany<SubGroup, $this>
     */
    public function subGroups(): HasMany
    {
        return $this->hasMany(SubGroup::class)->ordered();
    }

    /**
     * Kullanıcının görebildiği gruplar.
     *
     * "Hepsini getir sonra ekranda gizle" güvenlik değildir: daraltma
     * SORGUDA yapılır. Yönetici için daraltma yoktur.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(UserPermissionResolver::class)->scope($query, $user, IpAddressPolicy::RESOURCE, 'view');
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['name', 'description'];
    }
}
