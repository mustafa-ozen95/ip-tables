<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\GroupKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yetkilendirme: uç `can:viewAny` ve jeton yeteneğiyle korunur; içerik
 * visibleTo() ile daraltılır (bkz. IpAddressApiController).
 */
class IpAddressIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'grup' => ['sometimes', 'string', 'uuid'],
            'tur' => ['sometimes', 'string', Rule::enum(GroupKind::class)],
            'q' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
