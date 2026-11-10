<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Models\IpAddress;

class UpdateIpAddressRequest extends StoreIpAddressRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->record());
    }

    protected function record(): IpAddress
    {
        return $this->route('ipAddress');
    }
}
