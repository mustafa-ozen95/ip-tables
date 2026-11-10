<?php

declare(strict_types=1);

namespace App\Http\Requests\AddressGroup;

use App\Support\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;

class DestroyAddressGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('group'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /* TOPLU İŞLEM: gerekçe kayıttan bağımsız, HER ZAMAN istenir
               (bkz. ReasonRule::zorunlu). */
            'reason' => ReasonRule::zorunlu(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }
}
