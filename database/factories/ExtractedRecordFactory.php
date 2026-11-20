<?php

namespace Database\Factories;

use App\Models\CrawlTarget;
use App\Models\ExtractedRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExtractedRecord> */
class ExtractedRecordFactory extends Factory
{
    public function definition(): array
    {
        return ['crawl_target_id' => CrawlTarget::factory(), 'kind' => 'business', 'identity_key' => fake()->sha256(), 'title' => 'Example business', 'source_url' => 'https://example.com/', 'data' => ['name' => 'Example business', 'emails' => []], 'search_text' => 'Example business', 'last_seen_at' => now()];
    }
}
