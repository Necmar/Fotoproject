<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => CompanyStatus::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Company $company) {
            $company->settings()->create(CompanySetting::defaults());
        });
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => CompanyStatus::Blocked,
            'blocked_at' => now(),
        ]);
    }
}
