<?php

namespace App\Console\Commands;

use App\Exceptions\FetchException;
use App\Models\CrawlTarget;
use App\Services\Crawling\PageFetcher;
use Illuminate\Console\Command;

class FetchCrawlPage extends Command
{
    protected $signature = 'crawl:fetch {id : Enabled crawl target ID}';

    protected $description = 'Fetch a single starting page after public-address and robots checks';

    public function handle(PageFetcher $fetcher): int
    {
        $id = (string) $this->argument('id');

        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('A positive target ID is required.');

            return self::FAILURE;
        }

        $target = CrawlTarget::find($id);

        if ($target === null) {
            $this->error('Crawl target not found.');

            return self::FAILURE;
        }

        try {
            $page = $fetcher->fetch($target);
        } catch (FetchException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Fetched HTML successfully.');
        $this->line('HTTP '.$page['status'].'; '.strlen($page['html']).' bytes.');

        return self::SUCCESS;
    }
}
