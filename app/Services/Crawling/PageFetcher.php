<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;
use App\Models\CrawlTarget;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

class PageFetcher
{
    public function __construct(private PublicUrlResolver $resolver, private SafeHttpClient $http) {}

    /** @return array{url: string, status: int, html: string} */
    public function fetch(CrawlTarget $target, ?string $url = null, ?float $deadline = null): array
    {
        if (! $target->is_enabled) {
            throw new FetchException('Crawl target is disabled.');
        }

        $deadline = min($deadline ?? INF, now()->getTimestampMs() / 1000 + config('crawler.fetch_budget'));
        $starting = $this->resolver->resolve($target->url);
        $destination = $this->resolver->resolve($url ?? $starting['url'], $starting['host']);
        $url = $destination['url'];
        $policies = [];

        for ($redirect = 0; $redirect <= config('crawler.max_redirects'); $redirect++) {
            $uri = new Uri($url);
            $origin = (string) $uri->withPath('')->withQuery('')->withFragment('');
            $policy = $policies[$origin] ??= $this->robots($origin, $starting['host'], $deadline);

            if (! $policy->allows($url)) {
                throw new FetchException('Page is disallowed by robots.txt.');
            }

            if ($policy->delay() > config('crawler.max_wait')) {
                throw new FetchException('Robots crawl-delay exceeds this fetch budget; fetching paused.', retryAfter: (int) min(86400, ceil($policy->delay())));
            }

            $response = $this->http->get($url, $starting['host'], $policy->delay(), $deadline);

            if ($this->redirects($response)) {
                if ($redirect === config('crawler.max_redirects')) {
                    throw new FetchException('Page redirect limit exceeded.');
                }

                $url = $this->location($url, $response, $starting['host']);

                continue;
            }

            if (! $response->successful() || $response->status() === 206) {
                throw new FetchException('Page returned HTTP '.$response->status().'.');
            }

            $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

            if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)
                || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)) {
                throw new FetchException('Response is not uncompressed HTML.');
            }

            return ['url' => $url, 'status' => $response->status(), 'html' => $response->body()];
        }

        throw new FetchException('Page could not be fetched.');
    }

    private function robots(string $origin, string $host, float $deadline): RobotsRules
    {
        $url = $origin.'/robots.txt';

        for ($redirect = 0; $redirect <= config('crawler.max_redirects'); $redirect++) {
            $response = $this->http->get($url, $host, 0, $deadline, robots: true);

            if ($this->redirects($response)) {
                if ($redirect === config('crawler.max_redirects')) {
                    throw new FetchException('Robots redirect limit exceeded; fetching paused.');
                }

                $url = $this->location($url, $response, $host);

                continue;
            }

            if (in_array($response->status(), [404, 410], true)) {
                return new RobotsRules('', config('crawler.product_token'));
            }

            if ($response->status() !== 200) {
                throw new FetchException('Robots rules are unavailable; fetching paused.', retryAfter: 300);
            }

            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

            if (! in_array($type, ['', 'text/plain'], true)
                || str_starts_with(ltrim($response->body()), '<')
                || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)) {
                throw new FetchException('Robots response is not plain text; fetching paused.');
            }

            $rules = new RobotsRules($response->body(), config('crawler.product_token'));
            $this->http->respectRobotsDelay($host, $rules->delay());

            return $rules;
        }

        throw new FetchException('Robots rules could not be fetched.');
    }

    private function redirects(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function location(string $url, Response $response, string $host): string
    {
        $location = $response->header('Location');

        if ($location === '') {
            throw new FetchException('Redirect has no destination.');
        }

        if (preg_match('/[\x00-\x20\x7F\\\\]/', $location)) {
            throw new FetchException('Invalid redirect destination.');
        }

        try {
            $next = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        } catch (InvalidArgumentException $exception) {
            throw new FetchException('Invalid redirect destination.', previous: $exception);
        }

        if ((new Uri($url))->getScheme() === 'https' && (new Uri($next))->getScheme() !== 'https') {
            throw new FetchException('Redirect would downgrade HTTPS.');
        }

        return $this->resolver->resolve($next, $host)['url'];
    }
}
