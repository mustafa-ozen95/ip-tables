<?php

declare(strict_types=1);

namespace App\Http\Requests\AddressGroup;

use App\Enums\GroupKind;
use App\Services\IpAddressParser;
use Illuminate\Validation\Validator;

class UpdateAddressGroupRequest extends AddressGroupRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /**
     * TÜR DEĞİŞİMİ İÇERİDEKİ ADRESLERİ ÖKSÜZ BIRAKAMAZ.
     *
     * Adres eklenirken grubun türü sınanır (bkz. StoreIpAddressRequest);
     * türü sonradan değiştirmek o kuralı arka kapıdan atlamak olurdu.
     * Aynı kural iki yoldan da uygulanır.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $group = $this->route('group');
            $kind = $this->enum('kind', GroupKind::class);

            if ($validator->errors()->isNotEmpty() || $kind === null || $kind === $group->kind) {
                return;
            }

            $parser = app(IpAddressParser::class);
            $uyumsuz = $group->ipAddresses()->get(['address', 'prefix'])
                ->reject(fn ($ip): bool => $kind->accepts($parser->parse($ip->address)?->kind ?? GroupKind::Mixed))
                ->count();

            if ($uyumsuz > 0) {
                $validator->errors()->add('kind', "Grupta bu türe uymayan {$uyumsuz} adres var; önce onları taşıyın.");
            }
        }];
    }
}
