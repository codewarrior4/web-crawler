<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;

class PublicUrlResolver
{
    public function __construct(private DnsResolver $dns) {}

    /** @return array{url: string, host: string, port: int, ip: string} */
    public function resolve(string $url, ?string $expectedHost = null): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new FetchException('Invalid destination URL.');
        }

        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException $exception) {
            throw new FetchException('Invalid destination URL.', previous: $exception);
        }

        $host = strtolower(trim($uri->getHost(), '[]'));
        $scheme = $uri->getScheme();
        $parts = parse_url($url);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $parts === false
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchException('Only HTTP/HTTPS URLs without credentials are allowed.');
        }

        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            throw new FetchException('Only standard HTTP/HTTPS ports are allowed.');
        }

        if ($expectedHost !== null && $host !== $expectedHost) {
            throw new FetchException('Destination leaves the target hostname.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*$/D', $host)) {
                throw new FetchException('A public hostname is required.');
            }

            $addresses = $this->dns->addresses($host);
        }

        if ($addresses === []) {
            throw new FetchException('Destination has no usable DNS addresses.');
        }

        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
            $packed = @inet_pton($address);
            $multicast = $packed !== false && strlen($packed) === 4 && ord($packed[0]) >= 224;
            $nonGlobalIpv6 = $packed !== false && strlen($packed) === 16 && (ord($packed[0]) & 0xE0) !== 0x20;

            $transitionIpv6 = $packed !== false && strlen($packed) === 16
                && (substr($packed, 0, 2) === hex2bin('2002') || substr($packed, 0, 4) === hex2bin('20010000'));

            if ($public === false || $multicast || $nonGlobalIpv6 || $transitionIpv6) {
                throw new FetchException('Local, private, or reserved destinations are blocked.');
            }
        }

        return [
            'url' => (string) $uri->withFragment('')->withPath($uri->getPath() ?: '/'),
            'host' => $host,
            'port' => $port,
            'ip' => $addresses[0],
        ];
    }
}
