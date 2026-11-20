<?php

namespace Tests\Feature;

use App\Exceptions\FetchException;
use App\Models\CrawlTarget;
use App\Services\Crawling\DnsResolver;
use App\Services\Crawling\PageFetcher;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageFetcherTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function prepareFetch(string $robots = '', array $responses = []): void
    {
        $this->travelTo(now()->startOfSecond());
        Sleep::fake(syncWithCarbon: true);
        Cache::flush();
        Http::preventStrayRequests();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        Http::fake(array_merge([
            'https://example.com/robots.txt' => Http::response($robots, 200, ['Content-Type' => 'text/plain']),
            'https://example.com/' => Http::response('<html>Hello</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ], $responses));
    }

    private function target(): CrawlTarget
    {
        return CrawlTarget::factory()->make(['url' => 'https://example.com/']);
    }

    public function test_fetches_allowed_html_with_user_agent_and_request_spacing(): void
    {
        $this->prepareFetch();
        $start = now();
        $page = $this->app->make(PageFetcher::class)->fetch($this->target());

        $this->assertSame(['url' => 'https://example.com/', 'status' => 200, 'html' => '<html>Hello</html>'], $page);
        $this->assertSame(2.0, $start->diffInSeconds(now()));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', config('crawler.user_agent')));
    }

    public function test_pins_validated_dns_and_caps_the_real_transport(): void
    {
        $this->prepareFetch('', [
            'https://example.com/' => function (Request $request, array $options) {
                $factory = new CurlFactory(0);
                $handle = $factory->create(new PsrRequest('GET', $request->url()), $options);
                $factory->release($handle);

                $this->assertSame(['example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
                $this->assertSame(['http', 'https'], $options['protocols']);
                $this->assertFalse($options['allow_redirects']);
                $this->assertFalse($options['cookies']);
                $this->assertSame('', $options['proxy']);
                $this->assertTrue($options['verify']);
                $this->assertSame(5, $options['connect_timeout']);
                $this->assertSame(15, $options['timeout']);
                $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
                $this->assertTrue($options['curl'][CURLOPT_FORBID_REUSE]);
                try {
                    $options['on_headers'](new PsrResponse(200, ['Content-Length' => '3000000']));
                    $this->fail('Large Content-Length must be rejected.');
                } catch (FetchException) {
                    $this->assertTrue(true);
                }

                return Http::response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);
            },
        ]);
        $this->app->make(PageFetcher::class)->fetch($this->target());
        Http::assertSentCount(2);
    }

    public function test_disabled_target_does_not_send_any_requests(): void
    {
        $this->prepareFetch();
        try {
            $this->app->make(PageFetcher::class)->fetch(CrawlTarget::factory()->disabled()->make());
            $this->fail('Disabled target must fail.');
        } catch (FetchException $exception) {
            $this->assertSame('Crawl target is disabled.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_disallowed_path_is_not_requested(): void
    {
        $this->prepareFetch("User-agent: *\nDisallow: /");
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Disallowed page must fail.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('disallowed', $exception->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.com/');
    }

    public function test_redirect_to_robots_disallowed_path_is_not_requested(): void
    {
        $this->prepareFetch("User-agent: *\nDisallow: /secret", [
            'https://example.com/' => Http::response('', 302, ['Location' => '/secret']),
        ]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Disallowed redirect must fail.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('disallowed', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_follows_relative_page_redirects(): void
    {
        $this->prepareFetch('', [
            'https://example.com/' => Http::response('', 302, ['Location' => '/jobs#heading']),
            'https://example.com/jobs' => Http::response('<html>Jobs</html>', 200, ['Content-Type' => 'application/xhtml+xml']),
        ]);
        $this->assertSame('https://example.com/jobs', $this->app->make(PageFetcher::class)->fetch($this->target())['url']);
        Http::assertSentCount(3);
    }

    #[DataProvider('unsafeRedirects')]
    public function test_rejects_unsafe_redirect_without_following_it(string $location): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::response('', 302, ['Location' => $location])]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Unsafe redirect must fail.');
        } catch (FetchException) {
            Http::assertSentCount(2);
        }
    }

    /** @return array<string, array{string}> */
    public static function unsafeRedirects(): array
    {
        return [
            'https downgrade' => ['http://example.com/'],
            'off host' => ['https://other.example/'], 'private' => ['http://127.0.0.1/'],
            'credentials' => ['https://user:password@example.com/'], 'nonstandard port' => ['https://example.com:8080/'],
            'bad protocol' => ['file:///etc/passwd'], 'missing location' => [''],
        ];
    }

    public function test_stops_page_redirect_loops(): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::response('', 302, ['Location' => '/'])]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Redirect loop must fail.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('redirect limit', $exception->getMessage());
        }
        Http::assertSentCount(5);
    }

    public function test_follows_in_scope_robots_redirects(): void
    {
        $this->prepareFetch('', [
            'https://example.com/robots.txt' => Http::response('', 301, ['Location' => '/rules.txt']),
            'https://example.com/rules.txt' => Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain']),
        ]);
        $this->assertSame(200, $this->app->make(PageFetcher::class)->fetch($this->target())['status']);
        Http::assertSentCount(3);
    }

    public function test_stops_robots_redirect_loops(): void
    {
        $this->prepareFetch('', ['https://example.com/robots.txt' => Http::response('', 302, ['Location' => '/robots.txt'])]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Robots redirect loop must fail.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('Robots redirect limit', $exception->getMessage());
        }
        Http::assertSentCount(4);
    }

    #[DataProvider('robotsStatuses')]
    public function test_treats_missing_robots_as_no_rules(int $status): void
    {
        $this->prepareFetch('', ['https://example.com/robots.txt' => Http::response('', $status)]);
        $this->assertSame(200, $this->app->make(PageFetcher::class)->fetch($this->target())['status']);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{int}> */
    public static function robotsStatuses(): array
    {
        return ['not found' => [404], 'gone' => [410]];
    }

    #[DataProvider('unusableRobots')]
    public function test_pauses_on_uncertain_robots_without_fetching_the_page(int $status, string $body, array $headers): void
    {
        $this->prepareFetch('', ['https://example.com/robots.txt' => Http::response($body, $status, $headers)]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Uncertain robots must pause.');
        } catch (FetchException) {
            Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.com/');
        }
    }

    /** @return array<string, array{int, string, array<string, string>}> */
    public static function unusableRobots(): array
    {
        return [
            'forbidden' => [403, '', []], 'unauthorized' => [401, '', []],
            'server failure' => [503, '', []], 'rate limited' => [429, '', []],
            'html' => [200, '<html>not rules</html>', ['Content-Type' => 'text/html']],
            'html without type' => [200, '<html>not rules</html>', []],
            'json' => [200, '{}', ['Content-Type' => 'application/json']],
            'compressed' => [200, 'compressed', ['Content-Encoding' => 'gzip']],
        ];
    }

    public function test_honors_robots_crawl_delay(): void
    {
        $this->prepareFetch("User-agent: *\nCrawl-delay: 5");
        $start = now();
        $this->app->make(PageFetcher::class)->fetch($this->target());
        $this->assertSame(5.0, $start->diffInSeconds(now()));
        Http::assertSentCount(2);
    }

    public function test_pauses_instead_of_shortening_an_excessive_crawl_delay(): void
    {
        $this->prepareFetch("User-agent: *\nCrawl-delay: 300");
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Long delay must pause.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('crawl-delay exceeds', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_remembers_robots_delay_before_another_fetch_attempt(): void
    {
        $this->prepareFetch("User-agent: *\nCrawl-delay: 300");
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->app->make(PageFetcher::class)->fetch($this->target());
                $this->fail('The next robots request must respect the previous delay.');
            } catch (FetchException) {
                // The first attempt learns the delay; the second sends nothing.
            }
        }
        Http::assertSentCount(1);
    }

    public function test_retries_transient_errors_and_honors_retry_after(): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::sequence()
            ->push('', 503, ['Retry-After' => '7'])
            ->push('<html>Recovered</html>', 200, ['Content-Type' => 'text/html'])]);
        $start = now();
        $this->assertSame('<html>Recovered</html>', $this->app->make(PageFetcher::class)->fetch($this->target())['html']);
        $this->assertSame(9.0, $start->diffInSeconds(now()));
        Http::assertSentCount(3);
    }

    public function test_honors_retry_after_http_date(): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::sequence()
            ->push('', 429, ['Retry-After' => now()->addSeconds(12)->toRfc7231String()])
            ->push('<html>OK</html>', 200, ['Content-Type' => 'text/html'])]);
        $start = now();
        $this->app->make(PageFetcher::class)->fetch($this->target());
        $this->assertSame(12.0, $start->diffInSeconds(now()));
        Http::assertSentCount(3);
    }

    public function test_stops_after_three_failed_page_attempts(): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::response('', 500)]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Persistent server error must fail.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('bounded retries', $exception->getMessage());
        }
        Http::assertSentCount(4);
    }

    public function test_retries_connection_failures_and_fails_closed(): void
    {
        $attempts = 0;
        $this->prepareFetch('', ['https://example.com/' => function () use (&$attempts) {
            $attempts++;

            return Http::failedConnection();
        }]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Connection failures must stop.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('Connection failed', $exception->getMessage());
        }
        $this->assertSame(3, $attempts);
    }

    public function test_persists_long_retry_after_across_fetcher_instances(): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::response('', 429, ['Retry-After' => '3600'])]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->app->make(PageFetcher::class)->fetch($this->target());
                $this->fail('Long retry delay must pause.');
            } catch (FetchException $exception) {
                $this->assertStringContainsString('retry later', $exception->getMessage());
            }
        }
        Http::assertSentCount(2);
    }

    #[DataProvider('unusablePages')]
    public function test_rejects_unusable_page_responses(int $status, string $body, array $headers): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::response($body, $status, $headers)]);
        $this->expectException(FetchException::class);
        $this->app->make(PageFetcher::class)->fetch($this->target());
    }

    /** @return array<string, array{int, string, array<string, string>}> */
    public static function unusablePages(): array
    {
        return [
            'not found' => [404, '', []], 'forbidden' => [403, '', []],
            'partial' => [206, '<html>Partial</html>', ['Content-Type' => 'text/html']],
            'pdf' => [200, '%PDF', ['Content-Type' => 'application/pdf']],
            'missing type' => [200, '<html>Unknown</html>', []],
            'compressed' => [200, 'compressed', ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']],
        ];
    }

    public function test_rejects_body_larger_than_configured_limit(): void
    {
        $this->prepareFetch();
        config(['crawler.max_body_bytes' => 5]);
        $this->expectExceptionMessage('size limit');
        $this->app->make(PageFetcher::class)->fetch($this->target());
    }

    public function test_rejects_oversized_robots_before_page_fetch(): void
    {
        $this->prepareFetch(str_repeat('x', 20));
        config(['crawler.max_robots_bytes' => 5]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Oversized robots must fail.');
        } catch (FetchException) {
            Http::assertSentCount(1);
        }
    }

    public function test_revalidates_dns_before_each_attempt_to_prevent_rebinding(): void
    {
        $this->prepareFetch();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(
            ['93.184.216.34'], ['93.184.216.34'], ['93.184.216.34'], ['127.0.0.1'],
        );
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Rebound host must fail.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('blocked', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_stops_when_fetch_budget_cannot_accommodate_delay(): void
    {
        $this->prepareFetch();
        config(['crawler.fetch_budget' => 1]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Budget must be enforced.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('budget', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_an_existing_host_lock_prevents_another_fetch(): void
    {
        $this->prepareFetch();
        $lock = Cache::lock('crawler:host:'.hash('sha256', 'example.com').':lock', 120);
        $this->assertTrue($lock->get());
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('A concurrent host request must be deferred.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('Another request', $exception->getMessage());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_retries_robots_connection_failures_without_requesting_the_page(): void
    {
        $attempts = 0;
        $this->prepareFetch('', ['https://example.com/robots.txt' => function () use (&$attempts) {
            $attempts++;

            return Http::failedConnection();
        }]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Unreachable robots must stop fetching.');
        } catch (FetchException) {
            $this->assertSame(3, $attempts);
        }
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://example.com/');
    }

    public function test_rejects_off_host_robots_redirect(): void
    {
        $this->prepareFetch('', ['https://example.com/robots.txt' => Http::response('', 302, ['Location' => 'https://other.example/robots.txt'])]);
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target());
            $this->fail('Off-host robots redirect must pause.');
        } catch (FetchException $exception) {
            $this->assertStringContainsString('leaves the target', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_rejects_explicit_off_host_page_before_fetching_robots(): void
    {
        $this->prepareFetch();
        try {
            $this->app->make(PageFetcher::class)->fetch($this->target(), 'https://other.example/');
            $this->fail('Off-host page must fail.');
        } catch (FetchException) {
            Http::assertNothingSent();
        }
    }

    public function test_uses_fallback_spacing_for_invalid_retry_after(): void
    {
        $this->prepareFetch('', ['https://example.com/' => Http::sequence()
            ->push('', 500, ['Retry-After' => 'not-a-date'])
            ->push('<html>OK</html>', 200, ['Content-Type' => 'text/html'])]);
        $start = now();
        $this->app->make(PageFetcher::class)->fetch($this->target());
        $this->assertSame(4.0, $start->diffInSeconds(now()));
        Http::assertSentCount(3);
    }

    public function test_fetch_command_reports_success_for_a_saved_target(): void
    {
        $this->prepareFetch();
        $target = CrawlTarget::factory()->create();
        $this->artisan('crawl:fetch', ['id' => $target->id])
            ->expectsOutput('Fetched HTML successfully.')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_fetch_command_reports_a_robots_denial(): void
    {
        $this->prepareFetch("User-agent: *\nDisallow: /");
        $target = CrawlTarget::factory()->create();
        $this->artisan('crawl:fetch', ['id' => $target->id])
            ->expectsOutput('Page is disallowed by robots.txt.')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_fetch_command_rejects_unknown_target(): void
    {
        $this->artisan('crawl:fetch', ['id' => 999])->expectsOutput('Crawl target not found.')->assertFailed();
    }

    public function test_fetch_command_rejects_invalid_id(): void
    {
        $this->artisan('crawl:fetch', ['id' => 'abc'])->expectsOutput('A positive target ID is required.')->assertFailed();
    }
}
