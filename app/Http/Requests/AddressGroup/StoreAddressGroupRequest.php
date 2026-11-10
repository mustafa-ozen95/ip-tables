<?php

declare(strict_types=1);

namespace App\Http\Requests\AddressGroup;

use App\Models\AddressGroup;

class StoreAddressGroupRequest extends AddressGroupRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AddressGroup::class);
    }
}
