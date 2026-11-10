<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Models\IpAddress;
use App\Services\IpAddressParser;
use App\Services\Ipv4BlockRules;
use App\Services\ParsedAddress;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * Sıralı iki eş parçayı birleştirme (/29 + /29 → /28; bkz. Ipv4BlockRules).
 * Eş parça silindiği için düzenleme yanında silme yetkisi de aranır.
 */
class MergeIpAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->part()) && $this->user()->can('delete', $this->part());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $error = app(Ipv4BlockRules::class)->mergeError($this->part());

            if ($error !== null) {
                $validator->errors()->add('address', $error);
            }
        }];
    }

    public function part(): IpAddress
    {
        return $this->route('ipAddress');
    }

    public function buddy(): IpAddress
    {
        return app(Ipv4BlockRules::class)->buddy($this->part());
    }

    public function merged(): ParsedAddress
    {
        return app(IpAddressParser::class)->parse(app(Ipv4BlockRules::class)->mergedNotation($this->part()));
    }

    /** Hata pencere alanına değil, bildirim olarak döner: işlem bir düğmedir. */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(back()->with('error', $validator->errors()->first()));
    }
}
