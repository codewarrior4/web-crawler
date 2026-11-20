<?php

namespace Database\Factories;

use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrawlRun> */
class CrawlRunFactory extends Factory
{
    public function definition(): array
    {
        return ['crawl_target_id' => CrawlTarget::factory(), 'status' => 'pending', 'settings' => ['url' => 'https://example.com/', 'type' => 'business', 'is_enabled' => true, 'max_pages' => 25, 'max_depth' => 2]];
    }
}
