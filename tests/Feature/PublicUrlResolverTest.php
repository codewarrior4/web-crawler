<?php

namespace Tests\Feature;

use App\Exceptions\FetchException;
use App\Services\Crawling\DnsResolver;
use App\Services\Crawling\PublicUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicUrlResolverTest extends TestCase
{
    public function test_resolves_a_public_host_and_removes_fragments(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()
            ->with('example.com')->andReturn(['93.184.216.34', '2606:4700:4700::1111']);

        $this->assertSame([
            'url' => 'https://example.com/', 'host' => 'example.com', 'port' => 443, 'ip' => '93.184.216.34',
        ], $this->app->make(PublicUrlResolver::class)->resolve('https://example.com/#fragment'));
    }

    public function test_normalizes_empty_path_and_preserves_query(): void
    {
        $this->assertSame('https://8.8.8.8/?q=one', $this->app->make(PublicUrlResolver::class)->resolve('https://8.8.8.8?q=one#part')['url']);
    }

    public function test_accepts_public_ipv6_literal(): void
    {
        $this->assertSame('2606:4700:4700::1111', $this->app->make(PublicUrlResolver::class)->resolve('https://[2606:4700:4700::1111]/')['ip']);
    }

    #[DataProvider('blockedUrls')]
    public function test_rejects_unsafe_url_before_connecting(string $url): void
    {
        $this->expectException(FetchException::class);
        $this->app->make(PublicUrlResolver::class)->resolve($url);
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'], 'private' => ['http://10.0.0.1/'],
            'link local' => ['http://169.254.169.254/'], 'shared' => ['http://100.64.0.1/'],
            'unspecified' => ['http://0.0.0.0/'], 'documentation' => ['http://192.0.2.1/'],
            'multicast' => ['http://224.0.0.1/'], 'reserved' => ['http://240.0.0.1/'],
            '6to4 private tunnel' => ['http://[2002:7f00:1::]/'], 'teredo' => ['http://[2001::1]/'],
            'ipv6 loopback' => ['http://[::1]/'], 'ipv6 local' => ['http://[fd00::1]/'],
            'ipv6 link local' => ['http://[fe80::1]/'], 'mapped ipv4' => ['http://[::ffff:127.0.0.1]/'],
            'ipv6 docs' => ['http://[2001:db8::1]/'], 'ipv6 multicast' => ['http://[ff02::1]/'],
            'localhost' => ['http://localhost/'], 'short ipv4' => ['http://127.1/'],
            'decimal ipv4' => ['http://2130706433/'], 'hex ipv4' => ['http://0x7f000001/'],
            'credentials' => ['https://user:secret@example.com/'], 'empty credentials' => ['https://@example.com/'],
            'ftp' => ['ftp://example.com/'], 'file' => ['file:///etc/passwd'],
            'port' => ['https://example.com:8080/'], 'scheme relative' => ['//example.com/'],
            'newline' => ["https://example.com/\nprivate"], 'backslash' => ['https://example.com\\@127.0.0.1/'],
            'too long' => ['https://example.com/'.str_repeat('a', 2048)],
        ];
    }

    public function test_rejects_mixed_public_and_private_dns_answers(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()
            ->andReturn(['93.184.216.34', '127.0.0.1']);
        $this->expectException(FetchException::class);
        $this->app->make(PublicUrlResolver::class)->resolve('https://example.com/');
    }

    public function test_rejects_empty_dns_answers(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->andReturn([]);
        $this->expectExceptionMessage('no usable DNS');
        $this->app->make(PublicUrlResolver::class)->resolve('https://example.com/');
    }

    public function test_rejects_a_different_host_without_dns_lookup(): void
    {
        $this->mock(DnsResolver::class)->shouldNotReceive('addresses');
        $this->expectExceptionMessage('leaves the target');
        $this->app->make(PublicUrlResolver::class)->resolve('https://other.example/', 'example.com');
    }
}
