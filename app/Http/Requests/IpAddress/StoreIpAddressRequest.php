<?php

declare(strict_types=1);

namespace App\Http\Requests\IpAddress;

use App\Enums\Highlight;
use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Models\SubGroup;
use App\Policies\IpAddressPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\IpAddressParser;
use App\Services\Ipv4BlockRules;
use App\Services\ParsedAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adres yazan iki yolun (ekleme, düzenleme) ORTAK kuralı burada; düzenleme
 * bu sınıfı genişletir (UpdateIpAddressRequest). API ya da başka bir ekran
 * adres yazacaksa aynı kuralı buradan alır, kopyalamaz.
 *
 * Grup ve alt grup uuid ile gelir ve yetkiye göre çözülür: kullanıcı
 * "ekle" yetkisi olmayan bir grubun uuid'ini gönderse de kabul edilmez.
 */
class StoreIpAddressRequest extends FormRequest
{
    private ?AddressGroup $group = null;

    private ?SubGroup $subGroup = null;

    private ?ParsedAddress $parsed = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', IpAddress::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'group' => ['required', 'string', 'uuid'],
            'sub_group' => ['nullable', 'string', 'uuid'],
            'address' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'highlight' => ['nullable', Rule::enum(Highlight::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'group' => 'grup',
            'sub_group' => 'alt grup',
            'address' => 'adres',
            'name' => 'ad',
            'description' => 'açıklama',
            'highlight' => 'vurgu rengi',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->resolveGroup($validator)
                && $this->resolveSubGroup($validator)
                && $this->resolveAddress($validator);
        }];
    }

    /**
     * @return array{group: AddressGroup, subGroup: SubGroup|null, parsed: ParsedAddress,
     *               details: array{name: string|null, description: string|null, highlight: string|null}}
     */
    public function payload(): array
    {
        return [
            'group' => $this->group,
            'subGroup' => $this->subGroup,
            'parsed' => $this->parsed,
            'details' => [
                'name' => $this->validated('name'),
                'description' => $this->validated('description'),
                'highlight' => $this->validated('highlight') ?: null,
            ],
        ];
    }

    /** Düzenlenen adres; eklemede yok. */
    protected function record(): ?IpAddress
    {
        return null;
    }

    private function resolveGroup(Validator $validator): bool
    {
        $group = AddressGroup::query()->where('uuid', $this->string('group'))->first();

        // Mevcut grubunda kalan adres için "ekle" yetkisi aranmaz; "düzenle" yeter (authorize).
        $stays = $group !== null && $group->is($this->record()?->group);

        if ($group === null || (! $stays && ! app(UserPermissionResolver::class)
            ->allows($this->user(), IpAddressPolicy::RESOURCE, 'create', $group->getKey()))) {
            $validator->errors()->add('group', 'Bu gruba adres ekleme yetkiniz yok.');

            return false;
        }

        /*
         * Başka gruba TAŞIMAK, kaynak gruptan çıkarmaktır: orada "sil" yetkisi
         * olmayan biri "düzenle + hedefte ekle" ile grubu boşaltamamalı.
         */
        if (! $stays && $this->record() !== null && ! app(UserPermissionResolver::class)
            ->allows($this->user(), IpAddressPolicy::RESOURCE, 'delete', $this->record()->address_group_id)) {
            $validator->errors()->add('group', 'Adresi bu gruptan taşıma yetkiniz yok.');

            return false;
        }

        $this->group = $group;

        return true;
    }

    private function resolveSubGroup(Validator $validator): bool
    {
        if (blank($this->input('sub_group'))) {
            return true;
        }

        $this->subGroup = $this->group->subGroups()->where('uuid', $this->string('sub_group'))->first();

        if ($this->subGroup === null) {
            $validator->errors()->add('sub_group', 'Alt grup seçilen gruba ait değil.');

            return false;
        }

        return true;
    }

    private function resolveAddress(Validator $validator): bool
    {
        $this->parsed = app(IpAddressParser::class)->parse($this->string('address')->toString());

        $error = match (true) {
            $this->parsed === null => 'Geçerli bir IPv4/IPv6 adresi ya da blok (ör. 192.0.2.0/24) girin.',
            ! $this->group->kind->accepts($this->parsed->kind) => "{$this->parsed->notation()} bir {$this->parsed->kind->label()} adresi; "
                ."{$this->group->name} grubu yalnız {$this->group->kind->label()} kabul ediyor.",
            $this->isDuplicate() => "{$this->parsed->notation()} {$this->group->name} grubunda zaten kayıtlı.",
            /* IPv4'te yalnız /24 eklenir; parça ve bölünmüş blok yerinde kalır (bkz. Ipv4BlockRules). */
            default => app(Ipv4BlockRules::class)->writeError($this->record(), $this->group, $this->parsed),
        };

        if ($error !== null) {
            $validator->errors()->add('address', $error);
        }

        return $error === null;
    }

    private function isDuplicate(): bool
    {
        return $this->group->ipAddresses()
            ->where('address', $this->parsed->address)
            ->where('prefix', $this->parsed->prefix)
            ->when($this->record(), fn ($query, IpAddress $self) => $query->whereKeyNot($self->getKey()))
            ->exists();
    }
}
