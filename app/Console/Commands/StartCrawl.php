<?php

namespace App\Console\Commands;

use App\Models\CrawlTarget;
use App\Services\Crawling\CrawlManager;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class StartCrawl extends Command
{
    protected $signature = 'crawl:start {id : Target ID}';

    protected $description = 'Queue a bounded crawl for an enabled target';

    public function handle(CrawlManager $manager): int
    {
        $id = (string) $this->argument('id');
        $target = ctype_digit($id) ? CrawlTarget::find($id) : null;
        if ($target === null) {
            $this->error('Crawl target not found.');

            return self::FAILURE;
        }
        try {
            $run = $manager->start($target);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Crawl run '.$run->id.' queued.');

        return self::SUCCESS;
    }
}
