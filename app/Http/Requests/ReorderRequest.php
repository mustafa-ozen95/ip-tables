<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sürükleyerek sıralama (bkz. sorting.js): grup da alt grup da aynı biçimde
 * gelir — uuid dizisi, yeni sırasıyla.
 *
 * Yetkilendirme: rotanın controller'ı (`can:create` grup için, `can:update,group`
 * alt grup için) karar verir; burası yalnız biçimi doğrular.
 */
class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'sira' => ['required', 'array'],
            'sira.*' => ['string', 'uuid'],
        ];
    }

    /** @return list<string> */
    public function uuids(): array
    {
        return array_values(array_unique($this->validated('sira')));
    }
}
