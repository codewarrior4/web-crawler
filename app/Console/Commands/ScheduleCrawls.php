<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCrawl;
use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use App\Services\Crawling\CrawlManager;
use Illuminate\Console\Command;

class ScheduleCrawls extends Command
{
    protected $signature = 'crawler:schedule';

    protected $description = 'Queue due targets and recover paused or abandoned crawl runs';

    public function handle(CrawlManager $manager): int
    {
        foreach (CrawlTarget::where('is_enabled', true)->whereNotNull('schedule_hours')->where('next_crawl_at', '<=', now())->lazyById(100) as $target) {
            $manager->start($target);
            $target->update(['next_crawl_at' => now()->addHours($target->schedule_hours)]);
        }
        foreach (CrawlRun::whereNotNull('active_target_id')->where(function ($query): void {
            $query->where(fn ($query) => $query->where('status', 'paused')->where('retry_at', '<=', now()))
                ->orWhere(fn ($query) => $query->whereIn('status', ['pending', 'running'])->where('updated_at', '<', now()->subMinutes(5)));
        })->lazyById(100) as $run) {
            $run->update(['status' => 'pending', 'retry_at' => null, 'message' => 'Recovery queued.']);
            ProcessCrawl::dispatch($run->id);
        }
        $this->info('Due crawls and recovery work queued.');

        return self::SUCCESS;
    }
}
