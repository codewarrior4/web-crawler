<?php

namespace Database\Factories;

use App\Models\CrawlTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrawlTarget> */
class CrawlTargetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => 'https://example.com/',
            'type' => 'business',
            'is_enabled' => true,
            'max_pages' => 25,
            'max_depth' => 2,
        ];
    }

    public function jobs(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => 'job']);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => ['is_enabled' => false]);
    }
}
