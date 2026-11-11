<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Listede çift tıklayarak tek alan düzenleme: ad ya da açıklama.
 *
 * Adres, grup ve vurgu buradan değişmez; onlar düzenleme penceresinde.
 * Değerin kuralı pencereninkiyle AYNI (StoreIpAddressRequest::detailRules).
 */
class UpdateIpAddressFieldRequest extends FormRequest
{
    public const FIELDS = ['name', 'description'];

    public function authorize(): bool
    {
        return $this->user()->can('updateDetails', $this->route('ipAddress'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $field = $this->string('field')->value();

        return [
            'field' => ['required', Rule::in(self::FIELDS)],
            'value' => StoreIpAddressRequest::detailRules()[$field] ?? ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'field' => 'alan',
            'value' => $this->string('field')->value() === 'description' ? 'açıklama' : 'ad',
        ];
    }

    public function field(): string
    {
        return $this->validated('field');
    }

    /** Boş bırakılan alan silinir (null); baştaki/sondaki boşluk atılır. */
    public function value(): ?string
    {
        $value = trim((string) $this->validated('value'));

        return $value === '' ? null : $value;
    }
}
