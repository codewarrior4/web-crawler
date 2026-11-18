<?php

namespace Database\Seeders;

use App\Models\CrawlTarget;
use Illuminate\Database\Seeder;

class CrawlTargetSeeder extends Seeder
{
    public function run(): void
    {
        CrawlTarget::firstOrCreate(
            ['url' => 'https://automattic.com/contact/', 'type' => 'business'],
            ['name' => 'Automattic'],
        );

        CrawlTarget::firstOrCreate(
            ['url' => 'https://www.python.org/jobs/', 'type' => 'job'],
            ['name' => 'Python.org jobs'],
        );
    }
}
