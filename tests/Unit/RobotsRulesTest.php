<?php

namespace Tests\Unit;

use App\Exceptions\FetchException;
use App\Services\Crawling\LimitedBodyStream;
use App\Services\Crawling\RobotsRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RobotsRulesTest extends TestCase
{
    #[DataProvider('policies')]
    public function test_applies_matching_policy(string $text, string $path, bool $allowed): void
    {
        $this->assertSame($allowed, (new RobotsRules($text, 'WebCrawler'))->allows('https://example.com'.$path));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function policies(): array
    {
        return [
            'empty' => ['', '/anything', true],
            'deny all' => ["User-agent: *\nDisallow: /", '/', false],
            'prefix' => ["User-agent: *\nDisallow: /private", '/private/page', false],
            'allow longest' => ["User-agent: *\nDisallow: /private\nAllow: /private/public", '/private/public/page', true],
            'allow wins tie' => ["User-agent: *\nAllow: /same\nDisallow: /same", '/same', true],
            'empty disallow' => ["User-agent: *\nDisallow:", '/', true],
            'specific agent' => ["User-agent: *\nDisallow: /\nUser-agent: WebCrawler\nAllow: /", '/', true],
            'case insensitive agent' => ["User-agent: WEBCRAWLER\nDisallow: /", '/', false],
            'other agent' => ["User-agent: AnotherBot\nDisallow: /", '/', true],
            'combined groups' => ["User-agent: WebCrawler\nDisallow: /one\nUser-agent: WebCrawler\nDisallow: /two", '/two', false],
            'multiple agents' => ["User-agent: AnotherBot\nUser-agent: WebCrawler\nDisallow: /", '/', false],
            'wildcard' => ["User-agent: *\nDisallow: /*.pdf$", '/files/report.pdf', false],
            'end anchor' => ["User-agent: *\nDisallow: /*.pdf$", '/report.pdf?download=1', true],
            'query rule' => ["User-agent: *\nDisallow: /*?secret=", '/page?secret=one', false],
            'case sensitive path' => ["User-agent: *\nDisallow: /Private", '/private', true],
            'comments' => ["User-agent: * # all\nDisallow: /private # hidden", '/private', false],
            'percent unreserved' => ["User-agent: *\nDisallow: /private", '/%70rivate', false],
            'encoded reserved distinct' => ["User-agent: *\nDisallow: /one/two", '/one%2ftwo', true],
            'unicode path' => ["User-agent: *\nDisallow: /café", '/caf%C3%A9', false],
            'bom and crlf' => ["\xEF\xBB\xBFUser-agent: *\r\nDisallow: /", '/', false],
        ];
    }

    public function test_selects_longest_matching_agent_and_combines_delays(): void
    {
        $rules = new RobotsRules("User-agent: *\nCrawl-delay: 20\nUser-agent: Web\nDisallow: /\nUser-agent: WebCrawler\nAllow: /\nCrawl-delay: 5\nUser-agent: WebCrawler\nCrawl-delay: 7", 'WebCrawler');
        $this->assertSame(7.0, $rules->delay());
        $this->assertTrue($rules->allows('https://example.com/'));
    }

    public function test_rejects_invalid_crawl_delay(): void
    {
        $this->expectException(FetchException::class);
        new RobotsRules("User-agent: *\nCrawl-delay: later", 'WebCrawler');
    }

    public function test_pauses_on_oversized_robots_patterns(): void
    {
        $this->expectException(FetchException::class);
        new RobotsRules("User-agent: *\nDisallow: /".str_repeat('x', 2048), 'WebCrawler');
    }

    public function test_caps_chunked_body_writes_before_storing_the_overflow(): void
    {
        $stream = new LimitedBodyStream(5);
        $stream->write('123');
        try {
            $stream->write('456');
            $this->fail('Overflow must fail.');
        } catch (FetchException) {
            $this->assertSame('123', (string) $stream);
        }
    }

    public function test_allows_a_body_exactly_at_the_limit(): void
    {
        $stream = new LimitedBodyStream(5);
        $stream->write('12');
        $stream->write('345');
        $this->assertSame('12345', (string) $stream);
    }
}
