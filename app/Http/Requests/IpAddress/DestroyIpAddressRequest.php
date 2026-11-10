<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Services\Ipv4BlockRules;
use App\Support\ReasonRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * Silme geri alınamaz: gerekçe zorunludur ve etkinlik kaydına yazılır.
 * Onay penceresi alanı ekler; pencereyi atlayan istek burada durur.
 *
 * İçinde kayıt olan IPv4 bloğu silinmez: onları bloksuz bırakırdı
 * (bkz. Ipv4BlockRules; düzenlemedeki taşıma da aynı kuralla sınanır).
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rules = app(Ipv4BlockRules::class);
            $orphans = $rules->orphans($this->route('ipAddress'));

            if ($orphans > 0) {
                $validator->errors()->add('address', $rules->orphanMessage($orphans));
            }
        }];
    }

    /**
     * Blok kuralının reddini gösterecek alan onay penceresinde yok; mesaj
     * sayfanın bildirimine (toast) gider. Gerekçe hatası eskisi gibi.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($validator->errors()->has('address')) {
            throw new HttpResponseException(back()->with('error', $validator->errors()->first('address')));
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'gerekçe'];
    }
}
