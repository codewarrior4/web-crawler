<?php

namespace Database\Factories;

use App\Models\CrawledPage;
use App\Models\CrawlRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrawledPage> */
class CrawledPageFactory extends Factory
{
    public function definition(): array
    {
        return ['crawl_run_id' => CrawlRun::factory(), 'url' => 'https://example.com/', 'url_hash' => hash('sha256', 'https://example.com/'), 'status' => 'pending'];
    }
}
