<?php

namespace Tests\Feature;

use App\Exceptions\FetchException;
use App\Jobs\ProcessCrawl;
use App\Models\CrawledPage;
use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use App\Models\ExtractedRecord;
use App\Services\Crawling\CrawlManager;
use App\Services\Crawling\PageFetcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrawlPipelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function manager(array $pages): CrawlManager
    {
        Queue::fake();
        Cache::flush();
        $this->mock(PageFetcher::class)->shouldReceive('fetch')->andReturnUsing(function (CrawlTarget $target, string $url) use ($pages): array {
            $response = $pages[$url] ?? throw new FetchException('Page returned HTTP 404.');
            if ($response instanceof FetchException) {
                throw $response;
            }

            return ['url' => $url, 'html' => $response, 'status' => 200];
        });

        return app(CrawlManager::class);
    }

    private function drain(CrawlManager $manager, CrawlRun $run): void
    {
        for ($i = 0; $i < 20 && $manager->process($run->id); $i++) {
        }
    }

    public function test_bounded_crawl_survives_cycles_and_repeat_runs_without_duplicate_records(): void
    {
        $manager = $this->manager([
            'https://example.com/' => '<a href="/contact/">Contact</a><a href="/contact/?utm_source=x">Duplicate</a><a href="https://elsewhere.test/">Offsite</a>',
            'https://example.com/contact/' => '<script type="application/ld+json">{"@type":"Organization","name":"Acme","email":"hello@example.com"}</script><a href="/">Loop</a>',
        ]);
        $target = CrawlTarget::factory()->create(['max_pages' => 2]);
        $run = $manager->start($target);
        $this->assertSame($run->id, $manager->start($target)->id);
        Queue::assertPushed(ProcessCrawl::class, 1);
        $this->drain($manager, $run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $run->pages()->count());
        $this->assertDatabaseCount('extracted_records', 1);
        $next = $manager->start($target);
        $this->drain($manager, $next);
        $this->assertDatabaseCount('extracted_records', 1);
        $this->assertSame($next->id, ExtractedRecord::sole()->crawl_run_id);
    }

    public function test_depth_zero_fetches_only_the_seed_and_records_failures(): void
    {
        $manager = $this->manager(['https://example.com/' => '<a href="/child">Child</a>']);
        $target = CrawlTarget::factory()->create(['max_depth' => 0]);
        $run = $manager->start($target);
        $this->drain($manager, $run);
        $this->assertSame(1, $run->pages()->count());
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_page_limits_count_failed_pages_too(): void
    {
        $manager = $this->manager(['https://example.com/' => '<a href="/bad">Bad</a><a href="/other">Other</a>']);
        $target = CrawlTarget::factory()->create(['max_pages' => 2]);
        $run = $manager->start($target);
        $this->drain($manager, $run);
        $this->assertSame(2, $run->pages()->count());
        $this->assertSame(1, $run->pages()->where('status', 'failed')->count());
    }

    public function test_pauses_transient_failures_and_cancellation_releases_target(): void
    {
        $manager = $this->manager(['https://example.com/' => new FetchException('Host is rate limited.', retryAfter: 300)]);
        $target = CrawlTarget::factory()->create();
        $run = $manager->start($target);
        $this->assertFalse($manager->process($run->id));
        $this->assertSame('paused', $run->fresh()->status);
        $manager->cancel($run->fresh());
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_target_id);
        $this->assertNotSame($run->id, $manager->start($target)->id);
    }

    public function test_cancellation_during_fetch_discards_results(): void
    {
        Queue::fake();
        $target = CrawlTarget::factory()->create();
        $run = null;
        $this->mock(PageFetcher::class)->shouldReceive('fetch')->andReturnUsing(function () use (&$run): array {
            app(CrawlManager::class)->cancel($run);

            return ['url' => 'https://example.com/', 'html' => '<script type="application/ld+json">{"@type":"Organization","name":"Acme"}</script>', 'status' => 200];
        });
        $manager = app(CrawlManager::class);
        $run = $manager->start($target);
        $manager->process($run->id);
        $this->assertDatabaseCount('extracted_records', 0);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_run_deadline_stops_new_requests_and_marks_pending_pages_skipped(): void
    {
        $manager = $this->manager([]);
        $run = $manager->start(CrawlTarget::factory()->create());
        $run->update(['started_at' => now()->subMinutes(11)]);
        $this->assertFalse($manager->process($run->id));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('skipped', $run->pages()->sole()->status);
    }

    public function test_disabled_target_cannot_start(): void
    {
        $manager = $this->manager([]);
        $this->expectException(ValidationException::class);
        $manager->start(CrawlTarget::factory()->disabled()->create());
    }

    public function test_due_schedule_is_queued_once_and_respects_existing_active_run(): void
    {
        $this->manager([]);
        $target = CrawlTarget::factory()->create(['schedule_hours' => 24, 'next_crawl_at' => now()->subMinute()]);
        $this->artisan('crawler:schedule')->assertSuccessful();
        $this->artisan('crawler:schedule')->assertSuccessful();
        $this->assertSame(1, CrawlRun::count());
        Queue::assertPushed(ProcessCrawl::class, 1);
        $this->assertTrue($target->fresh()->next_crawl_at->isFuture());
    }

    public function test_recovery_resumes_a_running_page_without_duplicating_results(): void
    {
        $manager = $this->manager(['https://example.com/' => '<script type="application/ld+json">{"@type":"Organization","name":"Acme"}</script>']);
        $run = $manager->start(CrawlTarget::factory()->create(['max_depth' => 0]));
        $run->pages()->sole()->update(['status' => 'running', 'attempts' => 1]);
        $this->drain($manager, $run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $run->pages()->sole()->attempts);
        $this->assertDatabaseCount('extracted_records', 1);
        $this->assertFalse($manager->process($run->id));
    }

    public function test_worker_failure_sets_terminal_status_and_releases_target(): void
    {
        $manager = $this->manager([]);
        $run = $manager->start(CrawlTarget::factory()->create());
        (new ProcessCrawl($run->id))->failed(new \RuntimeException('Internal test failure'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_target_id);
        $this->assertSame('skipped', $run->pages()->sole()->status);
    }

    public function test_pending_frontier_is_capped_and_job_scope_keeps_application_links_outside_the_queue(): void
    {
        config(['crawler.max_pending_urls' => 1]);
        $manager = $this->manager(['https://example.com/jobs/' => '<a href="https://company.test/apply">Apply</a><a href="/other">Other section</a><a href="/jobs/1/">One</a><a href="/jobs/2/">Two</a>', 'https://example.com/jobs/1/' => '<h1>One</h1>']);
        $run = $manager->start(CrawlTarget::factory()->jobs()->create(['url' => 'https://example.com/jobs/']));
        $this->drain($manager, $run);
        $this->assertSame(2, $run->pages()->count());
        $this->assertFalse($run->pages()->where('url', 'like', '%company.test%')->exists());
    }

    public function test_scheduler_resumes_due_paused_work_but_not_cancelled_work(): void
    {
        $manager = $this->manager([]);
        $run = $manager->start(CrawlTarget::factory()->create());
        $run->update(['status' => 'paused', 'retry_at' => now()->subMinute()]);
        $this->artisan('crawler:schedule')->assertSuccessful();
        Queue::assertPushed(ProcessCrawl::class, 2);
        $this->assertSame('pending', $run->fresh()->status);
        $manager->cancel($run->fresh());
        $this->artisan('crawler:schedule')->assertSuccessful();
        Queue::assertPushed(ProcessCrawl::class, 2);
    }

    public function test_retention_preserves_active_runs_and_prunes_only_old_terminal_runs(): void
    {
        $target = CrawlTarget::factory()->create();
        $old = CrawlRun::factory()->create(['crawl_target_id' => $target->id, 'status' => 'completed', 'finished_at' => now()->subDays(40)]);
        CrawledPage::factory()->create(['crawl_run_id' => $old->id]);
        $active = CrawlRun::factory()->create(['crawl_target_id' => $target->id, 'active_target_id' => $target->id]);
        $this->artisan('crawler:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('crawl_runs', 2);
        $this->artisan('crawler:prune')->assertSuccessful();
        $this->assertDatabaseMissing('crawl_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('crawl_runs', ['id' => $active->id]);
        $this->assertDatabaseCount('crawled_pages', 0);
    }
}
