<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddressGroup;
use App\Models\IpAddress;
use App\Services\IpAddressParser;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * Sınama verisi. Adresler belgeleme aralıklarındandır (RFC 5737 / 3849):
 * gerçek bir ağa ait değildir.
 *
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'address_group_id' => AddressGroup::factory(),
            ...$this->addressAttributes('192.0.2.'.fake()->unique()->numberBetween(1, 254)),
            'name' => fake()->domainWord(),
            'description' => null,
        ];
    }

    public function address(string $input): self
    {
        return $this->state(fn (): array => $this->addressAttributes($input));
    }

    /** @return array<string, mixed> */
    private function addressAttributes(string $input): array
    {
        $parsed = app(IpAddressParser::class)->parse($input)
            ?? throw new InvalidArgumentException("Geçersiz adres: {$input}");

        return [
            'address' => $parsed->address,
            'prefix' => $parsed->prefix,
            'sort_key' => $parsed->sortKey,
        ];
    }
}
