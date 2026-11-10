<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Models\IpAddress;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\IpAddressParser;
use App\Services\Ipv4BlockRules;
use App\Services\Ipv4SplitLayout;
use App\Services\ParsedAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * /24 bloğu parçalara bölme (bkz. Ipv4BlockRules, Ipv4SplitLayout).
 *
 * Bölme gruba kayıt AÇAR: bloğu düzenleme yetkisi yetmez, grupta "ekle"
 * yetkisi de aranır — yoksa "düzenle" ile gruba 256 kayıt eklenebilirdi.
 */
class SplitIpAddressRequest extends FormRequest
{
    /** @var list<int> */
    private array $prefixes = [];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->block())
            && app(UserPermissionResolver::class)
                ->allows($this->user(), IpAddressPolicy::RESOURCE, 'create', $this->block()->address_group_id);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['layout' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['layout' => 'parçalar'];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rules = app(Ipv4BlockRules::class);
            $layout = app(Ipv4SplitLayout::class);
            $prefixes = $layout->parse($this->string('layout')->toString());

            $error = match (true) {
                ! $rules->isBlock($this->block()) => 'Yalnız IPv4 /'.Ipv4BlockRules::BLOCK_PREFIX.' blok bölünür.',
                $rules->parts($this->block())->isNotEmpty() => 'Bu blok zaten bölünmüş; yeniden bölmek için önce bölmesini kaldırın.',
                $prefixes === null => 'Parça boylarını sırasıyla yazın, ör. "29x10 28 28 27": "29x10" on tane /29 demektir.',
                default => $layout->error($this->block()->address, $prefixes),
            };

            if ($error !== null) {
                $validator->errors()->add('layout', $error);

                return;
            }

            $this->prefixes = $prefixes;
        }];
    }

    public function block(): IpAddress
    {
        return $this->route('ipAddress');
    }

    /** @return list<ParsedAddress> */
    public function parts(): array
    {
        $parser = app(IpAddressParser::class);

        return array_map(
            fn (string $cidr): ParsedAddress => $parser->parse($cidr),
            app(Ipv4SplitLayout::class)->parts($this->block()->address, $this->prefixes),
        );
    }

    /** Kayda yazılan kısa düzen ("/29×10 /28×2"). */
    public function layout(): string
    {
        return app(Ipv4SplitLayout::class)->describe($this->prefixes);
    }
}
