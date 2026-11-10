<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IpAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API'deki adres. Sıralı kimlik (id) dışarı çıkmaz; kimlik uuid'dir.
 *
 * @mixin IpAddress
 */
class IpAddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'kimlik' => $this->uuid,
            'adres' => $this->address,
            'onek' => $this->prefix,
            'gosterim' => $this->notation,
            'ad' => $this->name,
            'aciklama' => $this->description,
            'tur' => $this->whenLoaded('group', fn (): string => $this->group->kind->value),
            'grup' => $this->whenLoaded('group', fn (): array => [
                'kimlik' => $this->group->uuid,
                'ad' => $this->group->name,
            ]),
            'alt_grup' => $this->whenLoaded('subGroup', fn (): ?array => $this->subGroup === null ? null : [
                'kimlik' => $this->subGroup->uuid,
                'ad' => $this->subGroup->name,
            ]),
            'guncellendi' => $this->updated_at?->toIso8601String(),
        ];
    }
}
