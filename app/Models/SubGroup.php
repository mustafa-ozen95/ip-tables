<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SubGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir grubun içindeki bölme: "Grup A → Bölme 1", "Grup B → Bölme 2".
 *
 * YETKİ TAŞIMAZ. Grup yetki sınırıdır ve öyle kalır; alt grup yalnızca
 * kenar çubuğunda grubun altında ve listede başlık olarak görünür. "Bu rol
 * şu bölmeyi görmesin" gerekiyorsa bölme ayrı bir grup olur.
 *
 * Derinlik birdir: alt grubun altı yoktur. İkinci kat, "hangi seviye yetki
 * taşıyor" sorusunu geri getirirdi.
 */
class SubGroup extends Model
{
    /** @use HasFactory<SubGroupFactory> */
    use HasFactory;

    use HasUuid;

    protected $table = 'sub_groups';

    protected $fillable = [
        'address_group_id',
        'name',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AddressGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AddressGroup::class, 'address_group_id');
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class, 'sub_group_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }
}
