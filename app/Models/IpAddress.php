<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Highlight;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\Searchable;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Support\IpNotation;
use Database\Factories\IpAddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bir IP adresi ya da blok.
 *
 * Grubu YETKİ SINIRIDIR: adresi kim görür, kim değiştirir grubundan okunur
 * (bkz. IpAddressPolicy). Alt grup yalnız listedeki bölmedir, yetki taşımaz.
 *
 * @property string $uuid
 * @property string $address
 * @property int $prefix
 * @property string|null $name
 * @property string|null $description
 * @property Highlight|null $highlight
 * @property bool|null $gateway_up
 * @property Carbon|null $gateway_checked_at
 * @property Carbon|null $gateway_seen_at
 */
class IpAddress extends Model
{
    /** @use HasFactory<IpAddressFactory> */
    use HasFactory;

    use HasUuid;
    use Searchable;

    /*
     * address, prefix ve sort_key yalnız IpAddressParser'dan gelir
     * (bkz. IpAddressWriter); formdan doğrudan yazılmaz. Grup ve alt grup
     * da yazıcı tarafından, yetkisi doğrulanmış uuid'den çözülerek verilir.
     */
    protected $fillable = ['name', 'description', 'highlight'];

    protected $hidden = ['id', 'sort_key', 'address_group_id', 'sub_group_id'];

    protected function casts(): array
    {
        return [
            'prefix' => 'integer',
            'highlight' => Highlight::class,
            'gateway_up' => 'boolean',
            'gateway_checked_at' => 'datetime',
            'gateway_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AddressGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AddressGroup::class, 'address_group_id');
    }

    /** @return BelongsTo<SubGroup, $this> */
    public function subGroup(): BelongsTo
    {
        return $this->belongsTo(SubGroup::class, 'sub_group_id');
    }

    /** "192.0.2.0/24" ya da "192.0.2.10". */
    protected function notation(): Attribute
    {
        return Attribute::get(fn (): string => IpNotation::format($this->address, $this->prefix));
    }

    /** Listede ve onay penceresinde görünen ad: verilmişse ad, yoksa adres. */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->name ?: $this->notation);
    }

    /** Adresin yeri: "Grup · Alt grup" (alt grubu yoksa yalnız grup). group ve subGroup yüklü olmalı. */
    protected function location(): Attribute
    {
        return Attribute::get(fn (): string => collect([$this->group->name, $this->subGroup?->name])->filter()->implode(' · '));
    }

    /**
     * Kullanıcının görebildiği adresler: görme yetkisi olan grupların
     * içindekiler. "Hepsini getir, ekranda gizle" değil, sorgu daraltılır.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('address_group_id', app(UserPermissionResolver::class)->scope(
            AddressGroup::query()->select('id'),
            $user,
            IpAddressPolicy::RESOURCE,
            'view',
        ));
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_key')->orderBy('prefix');
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['address', 'name', 'description'];
    }
}
