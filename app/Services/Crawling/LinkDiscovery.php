<?php

namespace App\Services\Crawling;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class LinkDiscovery
{
    public function normalize(string $reference, string $base, ?string $host = null): ?string
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($reference === '' || strlen($reference) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $reference)) {
            return null;
        }
        try {
            $uri = UriResolver::resolve(new Uri($base), new Uri($reference))->withFragment('');
            if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '' || str_contains($uri->getAuthority(), '@')
                || ($host !== null && strtolower($uri->getHost()) !== strtolower($host))
                || ($uri->getPort() !== null && $uri->getPort() !== ($uri->getScheme() === 'https' ? 443 : 80))) {
                return null;
            }
            if ((new Uri($base))->getScheme() === 'https' && $uri->getScheme() === 'http') {
                return null;
            }
            $query = array_filter(explode('&', $uri->getQuery()), function (string $part): bool {
                $key = strtolower(rawurldecode(explode('=', $part, 2)[0]));

                return ! str_starts_with($key, 'utm_') && ! in_array($key, ['gclid', 'fbclid', 'msclkid'], true);
            });
            $path = preg_replace_callback('/%([0-9a-f]{2})/i', function (array $match): string {
                $character = chr(hexdec($match[1]));

                return preg_match('/[a-z0-9._~-]/i', $character) ? $character : strtoupper($match[0]);
            }, $uri->getPath() ?: '/');
            $uri = $uri->withPath(UriResolver::removeDotSegments($path))->withQuery(implode('&', $query));

            return strlen((string) $uri) <= 2048 ? (string) $uri : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    /** @return list<string> */
    public function links(string $html, string $url, int $limit = 100): array
    {
        $xpath = $this->document($html);
        foreach ($xpath->query('//meta[@name]') as $meta) {
            if (in_array(strtolower($meta->getAttribute('name')), ['robots', 'webcrawler'], true)
                && preg_match('/\b(nofollow|none)\b/i', $meta->getAttribute('content'))) {
                return [];
            }
        }
        $host = parse_url($url, PHP_URL_HOST);
        $base = $url;
        $baseNode = $xpath->query('//base[@href]')->item(0);
        if ($baseNode !== null) {
            $base = $this->normalize($baseNode->getAttribute('href'), $url, $host) ?? $url;
        }
        $links = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            if (preg_match('/\bnofollow\b/i', $anchor->getAttribute('rel'))) {
                continue;
            }
            $candidate = $this->normalize($anchor->getAttribute('href'), $base, $host);
            if ($candidate === null || preg_match('/\.(?:pdf|zip|gz|tar|jpg|jpeg|png|gif|svg|mp4|mp3|css|js|woff2?|exe|dmg|docx?|xlsx?)(?:$)/i', parse_url($candidate, PHP_URL_PATH) ?? '')) {
                continue;
            }
            $links[$candidate] = true;
            if (count($links) >= 1000) {
                break;
            }
        }
        $urls = array_keys($links);
        usort($urls, fn (string $a, string $b): int => $this->priority($b) <=> $this->priority($a));

        return array_slice($urls, 0, $limit);
    }

    private function priority(string $url): int
    {
        if (preg_match('~/jobs/\d+/?$~', parse_url($url, PHP_URL_PATH) ?? '')) {
            return 3;
        }

        return preg_match('~/(contact|about|jobs)(?:/|$)~i', parse_url($url, PHP_URL_PATH) ?? '') ? 1 : 0;
    }
}
