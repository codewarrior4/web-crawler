<?php

namespace Tests\Unit;

use App\Services\Crawling\ContentExtractor;
use App\Services\Crawling\LinkDiscovery;
use PHPUnit\Framework\TestCase;

class ContentExtractorTest extends TestCase
{
    private function extractor(): ContentExtractor
    {
        return new ContentExtractor(new LinkDiscovery);
    }

    public function test_extracts_organization_graph_with_normalized_contacts(): void
    {
        $html = '<script type="application/ld+json">{"@graph":[{"@type":"Organization","name":"Acme","url":"https://example.com/","email":["mailto:HELLO@example.com","bad"],"telephone":"+1 (234) 567-8901","address":{"streetAddress":"10 Main St","addressLocality":"Lagos"}}]}</script>';
        $records = $this->extractor()->extract($html, 'https://example.com/contact/', 'business');
        $this->assertCount(1, $records);
        $this->assertSame(['hello@example.com'], $records[0]['data']['emails']);
        $this->assertSame(['+12345678901'], $records[0]['data']['phones']);
        $this->assertSame('10 Main St, Lagos', $records[0]['data']['address']);
    }

    public function test_contact_fallback_does_not_assign_third_party_support_emails(): void
    {
        $html = '<title>Contact – Acme</title><meta property="og:site_name" content="Acme"><main><a href="mailto:hello@example.com">Us</a><a href="mailto:support@other.com">Other product</a><a href="tel:+12345678901">Call</a><address>10 Main Street</address></main>';
        $record = $this->extractor()->extract($html, 'https://example.com/contact/', 'business')[0];
        $this->assertSame('Acme', $record['title']);
        $this->assertSame(['hello@example.com'], $record['data']['emails']);
        $this->assertSame('10 Main Street', $record['data']['address']);
    }

    public function test_extracts_multiple_jobs_and_keeps_missing_values_empty(): void
    {
        $html = '<script type="application/ld+json">[{"@type":"JobPosting","title":"Engineer","hiringOrganization":{"name":"Acme"},"description":"<p>Build things</p>","jobLocation":{"address":{"addressLocality":"Lagos"}},"validThrough":"2020-01-01","baseSalary":{"currency":"NGN","value":{"minValue":100,"maxValue":200,"unitText":"MONTH"}}},{"@type":"JobPosting","title":"Designer"}]</script>';
        $records = $this->extractor()->extract($html, 'https://example.com/jobs/', 'job');
        $this->assertCount(2, $records);
        $this->assertSame('Build things', $records[0]['data']['description']);
        $this->assertSame('Lagos', $records[0]['data']['location']);
        $this->assertSame('2020-01-01 00:00:00', $records[0]['expires_at']);
        $this->assertSame('', $records[1]['data']['company']);
        $this->assertNotSame($records[0]['identity_key'], $records[1]['identity_key']);
    }

    public function test_schema_type_urls_and_invalid_dates_do_not_drop_jobs_or_invent_expiry_dates(): void
    {
        $html = '<script type="application/ld+json">[{"@type":"https://schema.org/JobPosting","title":"One","validThrough":"2026-02-31"},{"@type":"https://schema.org/JobPosting","title":"Two"}]</script>';
        $records = $this->extractor()->extract($html, 'https://example.com/jobs/', 'job');
        $this->assertCount(2, $records);
        $this->assertNull($records[0]['expires_at']);
    }

    public function test_job_identity_is_stable_when_title_changes_and_application_urls_are_safe(): void
    {
        $first = $this->extractor()->extract('<script type="application/ld+json">{"@type":"JobPosting","title":"Engineer","applicationUrl":"javascript:alert(1)"}</script>', 'https://example.com/jobs/1', 'job')[0];
        $next = $this->extractor()->extract('<script type="application/ld+json">{"@type":"JobPosting","title":"Senior Engineer"}</script>', 'https://example.com/jobs/1', 'job')[0];
        $this->assertSame($first['identity_key'], $next['identity_key']);
        $this->assertSame('https://example.com/jobs/1', $first['data']['application_url']);
    }

    public function test_ignores_malformed_json_and_personal_profiles(): void
    {
        $this->assertSame([], $this->extractor()->extract('<script type="application/ld+json">broken</script><script type="application/ld+json">{"@type":"Person","name":"Someone"}</script>', 'https://example.com/', 'business'));
    }

    public function test_python_org_heading_and_text_node_layout_is_parsed_without_mixing_fields(): void
    {
        $html = '<h1 class="listing-company"><span class="listing-company-name"><span class="company-name">Senior Engineer <span class="listing-new">New</span><br>Acme Group</span></span><span class="listing-location">Lagos</span></h1><div class="job-description"><h2>Job Title </h2>Senior Engineer<h2>Job Description</h2><p>Build systems</p></div>';
        $record = $this->extractor()->extract($html, 'https://www.python.org/jobs/123/', 'job')[0];
        $this->assertSame('Senior Engineer', $record['title']);
        $this->assertSame('Acme Group', $record['data']['company']);
        $this->assertSame('Lagos', $record['data']['location']);
    }

    public function test_python_org_detail_fallback_extracts_job_fields(): void
    {
        $html = '<h1>Engineer</h1><span class="listing-company-name">Acme</span><span class="listing-company-location">Remote</span><div class="job-description"><p>Build services.</p></div><h2>Contact Info</h2><ul><li><a href="https://company.test/apply">Apply</a></li></ul><time datetime="2026-11-01">Posted</time>';
        $record = $this->extractor()->extract($html, 'https://www.python.org/jobs/123/', 'job')[0];
        $this->assertSame('Acme', $record['data']['company']);
        $this->assertSame('Remote', $record['data']['location']);
        $this->assertSame('https://company.test/apply', $record['data']['application_url']);
    }
}
