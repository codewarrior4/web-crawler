<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;

class SafeHttpClient
{
    public function __construct(private PublicUrlResolver $resolver) {}

    public function get(string $url, string $host, float $delay, float $deadline, bool $robots = false): Response
    {
        for ($attempt = 0; $attempt <= config('crawler.max_retries'); $attempt++) {
            $destination = $this->resolver->resolve($url, $host);

            try {
                $response = $this->request($destination, $delay, $deadline, $robots);
            } catch (ConnectionException $exception) {
                if ($attempt === config('crawler.max_retries')) {
                    throw new FetchException('Connection failed after bounded retries.', previous: $exception);
                }

                continue;
            }

            if ($response->status() !== 429 && ! $response->serverError()) {
                return $response;
            }

            if ($attempt === config('crawler.max_retries')) {
                throw new FetchException('Remote rate limit or server error persisted after bounded retries.');
            }
        }

        throw new FetchException('Request could not be completed.');
    }

    /** @param array{url: string, host: string, port: int, ip: string} $destination */
    private function request(array $destination, float $delay, float $deadline, bool $robots): Response
    {
        if (! extension_loaded('curl')) {
            throw new FetchException('The cURL extension is required for pinned public connections.');
        }

        $key = 'crawler:host:'.hash('sha256', $destination['host']);
        $lock = Cache::lock($key.':lock', 120);

        if (! $lock->get()) {
            throw new FetchException('Another request is using this hostname; retry later.');
        }

        $started = false;
        $cooldown = 0;
        $delay = max((float) config('crawler.request_delay'), $delay, (float) Cache::get($key.':robots-delay', 0));

        try {
            $state = Cache::get($key, ['finished_at' => 0, 'next_at' => 0]);
            $wait = max(0, $state['next_at'] - $this->time(), $state['finished_at'] + $delay - $this->time());

            if ($wait > config('crawler.max_wait') || $this->time() + $wait >= $deadline) {
                throw new FetchException('Host delay exceeds this fetch budget; retry later.');
            }

            if ($wait > 0) {
                Sleep::usleep((int) ceil($wait * 1_000_000));
            }

            $remaining = $deadline - $this->time();

            if ($remaining <= 0) {
                throw new FetchException('Fetch time budget exhausted.');
            }

            $limit = (int) config($robots ? 'crawler.max_robots_bytes' : 'crawler.max_body_bytes');
            $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
            $started = true;

            $response = Http::withUserAgent(config('crawler.user_agent'))
                ->withHeaders(['Accept' => $robots ? 'text/plain' : 'text/html, application/xhtml+xml', 'Accept-Encoding' => 'identity'])
                ->connectTimeout(min(config('crawler.connect_timeout'), $remaining))
                ->timeout(min(config('crawler.request_timeout'), $remaining))
                ->setHandler(new CurlHandler)
                ->withOptions([
                    'allow_redirects' => false,
                    'cookies' => false,
                    'proxy' => '',
                    'protocols' => ['http', 'https'],
                    'verify' => true,
                    'decode_content' => false,
                    'sink' => new LimitedBodyStream($limit),
                    'on_headers' => function (ResponseInterface $response) use ($limit): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new FetchException('Response exceeds the configured size limit.');
                        }
                    },
                    'curl' => [
                        CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip],
                        CURLOPT_FRESH_CONNECT => true,
                        CURLOPT_FORBID_REUSE => true,
                    ],
                ])->get($destination['url']);

            if ($response->status() === 429 || $response->serverError()) {
                $cooldown = max($delay, 2);
                $header = trim($response->header('Retry-After'));

                if ($header !== '') {
                    try {
                        $cooldown = ctype_digit($header)
                            ? max($cooldown, (float) $header)
                            : max($cooldown, (new DateTimeImmutable($header))->getTimestamp() - $this->time());
                    } catch (Exception) {
                        // Invalid advice falls back to our own request delay.
                    }
                }
            }

            if (strlen($response->body()) > $limit) {
                throw new FetchException('Response exceeds the configured size limit.');
            }

            return $response;
        } catch (GuzzleRequestException $exception) {
            throw new FetchException('Response transfer was rejected.', previous: $exception);
        } finally {
            if ($started) {
                Cache::put($key, [
                    'finished_at' => $this->time(),
                    'next_at' => $this->time() + max($delay, $cooldown),
                ], (int) min(PHP_INT_MAX, ceil(max($delay, $cooldown)) + 3600));
            }

            $lock->release();
        }
    }

    public function respectRobotsDelay(string $host, float $delay): void
    {
        Cache::put('crawler:host:'.hash('sha256', $host).':robots-delay', $delay, (int) min(PHP_INT_MAX, ceil($delay) + 86400));
    }

    private function time(): float
    {
        return now()->getTimestampMs() / 1000;
    }
}
