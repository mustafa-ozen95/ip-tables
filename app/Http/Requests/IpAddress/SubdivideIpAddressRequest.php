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
 * Parçayı seçilen boyda eş parçalara bölme (/28 → 4 × /30; bkz.
 * Ipv4BlockRules). İlk parça kaydın kendisidir; ötekiler yeni kayıttır:
 * düzenleme yanında grupta "ekle" de aranır.
 */
class SubdivideIpAddressRequest extends FormRequest
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
        return ['prefix' => ['required', 'integer']];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $error = app(Ipv4BlockRules::class)->subdivideError($this->part(), $this->integer('prefix'));

            if ($error !== null) {
                $validator->errors()->add('address', $error);
            }
        }];
    }

    public function part(): IpAddress
    {
        return $this->route('ipAddress');
    }

    /** @return list<ParsedAddress> sıralı; ilki kaydın yeni hâli */
    public function pieces(): array
    {
        $parser = app(IpAddressParser::class);
        $part = $this->part();

        return array_map(
            fn ($piece): ParsedAddress => $parser->parse((string) $piece),
            Cidr::make($part->address, $part->prefix)->splitInto($this->integer('prefix')),
        );
    }

    /** Hata pencere alanına değil, bildirim olarak döner: işlem bir düğmedir. */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(back()->with('error', $validator->errors()->first()));
    }
}
