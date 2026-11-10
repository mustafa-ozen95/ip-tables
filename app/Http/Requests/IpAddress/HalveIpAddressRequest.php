<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Models\IpAddress;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\IpAddressParser;
use App\Services\Ipv4BlockRules;
use App\Services\ParsedAddress;
use App\Support\Net\Cidr;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * Parçayı iki eş parçaya ayırma (/28 → /29 + /29; bkz. Ipv4BlockRules).
 * Arkadaki yarı yeni kayıttır: düzenleme yanında grupta "ekle" de aranır.
 */
class HalveIpAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->part())
            && app(UserPermissionResolver::class)
                ->allows($this->user(), IpAddressPolicy::RESOURCE, 'create', $this->part()->address_group_id);
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
            $error = app(Ipv4BlockRules::class)->halveError($this->part());

            if ($error !== null) {
                $validator->errors()->add('address', $error);
            }
        }];
    }

    public function part(): IpAddress
    {
        return $this->route('ipAddress');
    }

    /** @return array{0: ParsedAddress, 1: ParsedAddress} */
    public function halves(): array
    {
        $parser = app(IpAddressParser::class);
        $part = $this->part();
        $half = Cidr::make($part->address, $part->prefix)->splitInto($part->prefix + 1);

        return [$parser->parse((string) $half[0]), $parser->parse((string) $half[1])];
    }

    /** Hata pencere alanına değil, bildirim olarak döner: işlem bir düğmedir. */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(back()->with('error', $validator->errors()->first()));
    }
}
