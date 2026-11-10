<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GroupKind;
use App\Models\AddressGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AddressGroup> */
class AddressGroupFactory extends Factory
{
    protected $model = AddressGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => GroupKind::Mixed,
            'description' => null,
            'position' => 0,
        ];
    }
}
