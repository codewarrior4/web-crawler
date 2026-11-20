<?php

namespace App\Console\Commands;

use App\Models\CrawlRun;
use App\Models\ExtractedRecord;
use Illuminate\Console\Command;

class PruneCrawls extends Command
{
    protected $signature = 'crawler:prune {--days= : Retain terminal run logs for this many days} {--dry-run : Show counts without deleting anything}';

    protected $description = 'Apply run-log and stale-record retention; active runs are preserved';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('crawler.retention_days');
        if (! ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 3650) {
            $this->error('Retention days must be between 1 and 3650.');

            return self::FAILURE;
        }
        $runs = CrawlRun::whereIn('status', ['completed', 'failed', 'cancelled'])->whereNull('active_target_id')->where('finished_at', '<', now()->subDays((int) $days));
        $records = ExtractedRecord::where('last_seen_at', '<', now()->subDays(config('crawler.record_retention_days')));
        $this->line($runs->count().' terminal runs; '.$records->count().' stale records.');
        if (! $this->option('dry-run')) {
            // Delete individually so MySQL and SQLite both apply foreign-key cascades.
            foreach ($runs->lazyById(100) as $run) {
                $run->delete();
            }
            $records->delete();
            $this->info('Retention applied.');
        }

        return self::SUCCESS;
    }
}
