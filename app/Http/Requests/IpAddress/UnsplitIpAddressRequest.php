<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Services\Ipv4BlockRules;
use Illuminate\Validation\Validator;

/**
 * Bloğun bölmesini kaldırmak, parçaları SİLMEKTİR: silmeyle aynı yetki,
 * aynı gerekçe ve aynı onay penceresi (bkz. DestroyIpAddressRequest).
 * Ret mesajı da aynı yoldan bildirime gider.
 */
class UnsplitIpAddressRequest extends DestroyIpAddressRequest
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (app(Ipv4BlockRules::class)->parts($this->route('ipAddress'))->isEmpty()) {
                $validator->errors()->add('address', 'Bu blok bölünmemiş.');
            }
        }];
    }
}
