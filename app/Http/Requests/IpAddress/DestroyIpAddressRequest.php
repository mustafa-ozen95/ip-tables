<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Support\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Silme geri alınamaz: gerekçe zorunludur ve etkinlik kaydına yazılır.
 * Onay penceresi alanı ekler; pencereyi atlayan istek burada durur.
 */
class DestroyIpAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('ipAddress'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['reason' => ReasonRule::zorunlu()];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }
}
