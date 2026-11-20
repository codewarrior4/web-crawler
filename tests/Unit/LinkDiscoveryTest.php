<?php

namespace Tests\Unit;

use App\Services\Crawling\LinkDiscovery;
use PHPUnit\Framework\TestCase;

class LinkDiscoveryTest extends TestCase
{
    public function test_resolves_deduplicates_and_filters_links(): void
    {
        $html = '<a href="../contact/?utm_source=x#top">Contact</a><a href="/contact/">Again</a><a href="https://other.test/">Outside</a><a href="mailto:team@example.com">Mail</a><a href="/file.pdf">PDF</a><a rel="nofollow" href="/ignored">Skip</a><a href="/jobs/?page=2&fbclid=x">Jobs</a>';
        $this->assertSame(['https://example.com/contact/', 'https://example.com/jobs/?page=2'], (new LinkDiscovery)->links($html, 'https://example.com/about/'));
    }

    public function test_prioritizes_job_details_over_navigation_before_applying_the_frontier_limit(): void
    {
        $html = '<a href="/jobs/types/">Types</a><a href="/jobs/type/backend/">Backend</a><a href="/jobs/42/">A job</a>';
        $this->assertSame(['https://example.com/jobs/42/'], (new LinkDiscovery)->links($html, 'https://example.com/jobs/', 1));
    }

    public function test_preserves_meaningful_queries_and_distinct_trailing_slashes(): void
    {
        $links = new LinkDiscovery;
        $this->assertSame('https://example.com/items?id=2&id=3', $links->normalize('/items?utm_source=x&id=2&id=3', 'https://example.com/'));
        $this->assertNotSame($links->normalize('/items/', 'https://example.com/'), $links->normalize('/items', 'https://example.com/'));
        $this->assertSame('https://example.com/public', $links->normalize('/%2e%2e/public#top', 'https://example.com/'));
    }

    public function test_ignores_offsite_base_and_advisory_canonical_and_honors_nofollow(): void
    {
        $links = new LinkDiscovery;
        $this->assertSame(['https://example.com/path/child'], $links->links('<base href="https://evil.test/"><link rel="canonical" href="https://evil.test/"><a href="child">Child</a>', 'https://example.com/path/'));
        $this->assertSame([], $links->links('<meta name="robots" content="noindex,nofollow"><a href="/child">Child</a>', 'https://example.com/'));
    }

    public function test_bounds_frontier_and_rejects_credentials_and_downgrades(): void
    {
        $links = new LinkDiscovery;
        $this->assertCount(1, $links->links('<a href="/one">One</a><a href="/two">Two</a>', 'https://example.com/', 1));
        $this->assertNull($links->normalize('http://example.com/', 'https://example.com/'));
        $this->assertNull($links->normalize('https://user:secret@example.com/', 'https://example.com/'));
        $this->assertNull($links->normalize('javascript:alert(1)', 'https://example.com/'));
    }
}
