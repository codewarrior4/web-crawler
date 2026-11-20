<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Services\Crawling\CrawlManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCrawl implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $runId) {}

    public function handle(CrawlManager $manager): void
    {
        if ($manager->process($this->runId)) {
            self::dispatch($this->runId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = CrawlRun::find($this->runId);
        if ($run?->isActive()) {
            app(CrawlManager::class)->finish($run, 'failed', 'Worker failed after retries. Check the worker logs, then start a new crawl.');
        }
    }
}
